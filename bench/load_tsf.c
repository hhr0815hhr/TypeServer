// TSF 帧协议压测端（closed-loop：收到回包立刻发下一条）
//
// 帧：| len(4B,含头) | msgId(2B) | flags(1B) | pad(1B) | body(16B: seq+ts) |
// 服务端把 body 原样回写，客户端据此算 RTT。
//
// 用法：./load_tsf 127.0.0.1 9001 <conns> <seconds>
#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/epoll.h>
#include <sys/socket.h>
#include <time.h>
#include <unistd.h>

#define MAXC 512
#ifndef BODY_SIZE
#define BODY_SIZE 16
#endif
#define FRAME (8 + BODY_SIZE)
#define BODY BODY_SIZE
#define BUCKET_US 10
#define MAX_BUCKET 20000

typedef struct {
    int fd;
    unsigned char buf[FRAME * 8 + 64];
    int buflen;
    long long send_ns;
    unsigned long long sent;
    unsigned long long recv;
} conn_t;

static conn_t g[MAXC];
static int g_n = 0;
static unsigned long long g_hist[MAX_BUCKET];

static long long now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (long long) ts.tv_sec * 1000000000LL + ts.tv_nsec;
}

static void build_frame(unsigned char *out, long long seq, long long ts) {
    unsigned int len = FRAME;
    out[0] = (unsigned char) ((len >> 24) & 0xFF);
    out[1] = (unsigned char) ((len >> 16) & 0xFF);
    out[2] = (unsigned char) ((len >> 8) & 0xFF);
    out[3] = (unsigned char) (len & 0xFF);
    out[4] = (unsigned char) (1001 >> 8); /* msgId */
    out[5] = (unsigned char) (1001 & 0xFF);
    out[6] = 0;
    out[7] = 0;
    memcpy(out + 8, &seq, 8);
    memcpy(out + 16, &ts, 8);
    if (BODY_SIZE > 16) {
        memset(out + 24, 0x41, (size_t) (BODY_SIZE - 16));
    }
}

static int add_conn(int ep, struct sockaddr_in *sa) {
    if (g_n >= MAXC) return -1;
    int fd = socket(AF_INET, SOCK_STREAM | SOCK_NONBLOCK, 0);
    if (fd < 0) return -1;
    int one = 1;
    setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof one);
    connect(fd, (struct sockaddr *) sa, sizeof *sa);
    struct epoll_event ev = {0};
    ev.events = EPOLLIN | EPOLLET;
    ev.data.u64 = (unsigned long long) g_n;
    epoll_ctl(ep, EPOLL_CTL_ADD, fd, &ev);
    g[g_n].fd = fd;
    g[g_n].buflen = 0;
    g[g_n].sent = 0;
    g[g_n].recv = 0;
    g_n++;
    return 0;
}

static void pct(double *p50, double *p99, double *mx) {
    unsigned long long total = 0;
    for (int i = 0; i < MAX_BUCKET; i++) total += g_hist[i];
    if (!total) {
        *p50 = *p99 = *mx = 0;
        return;
    }
    unsigned long long acc = 0;
    int g50 = 0, g99 = 0;
    for (int i = 0; i < MAX_BUCKET; i++) {
        if (!g_hist[i]) continue;
        acc += g_hist[i];
        if (!g50 && acc >= total * 0.50) {
            *p50 = i * BUCKET_US / 1000.0;
            g50 = 1;
        }
        if (!g99 && acc >= total * 0.99) {
            *p99 = i * BUCKET_US / 1000.0;
            g99 = 1;
        }
        *mx = i * BUCKET_US / 1000.0;
    }
}

int main(int argc, char **argv) {
    if (argc < 5) {
        fprintf(stderr, "usage: %s host port conns seconds\n", argv[0]);
        return 1;
    }
    const char *host = argv[1];
    int port = atoi(argv[2]);
    int want = atoi(argv[3]);
    int secs = atoi(argv[4]);
    if (want > MAXC) want = MAXC;

    struct sockaddr_in sa;
    memset(&sa, 0, sizeof sa);
    sa.sin_family = AF_INET;
    sa.sin_port = htons(port);
    inet_pton(AF_INET, host, &sa.sin_addr);

    int ep = epoll_create1(0);
    struct epoll_event *evs = calloc(MAXC, sizeof *evs);

    for (int i = 0; i < want; i++) add_conn(ep, &sa);
    usleep(500000); /* 建连 */

    /* 每连接先发一条 */
    unsigned char out[FRAME];
    for (int i = 0; i < g_n; i++) {
        long long ts = now_ns();
        build_frame(out, i, ts);
        if (send(g[i].fd, out, FRAME, MSG_NOSIGNAL) == FRAME) {
            g[i].send_ns = ts;
            g[i].sent++;
        }
    }

    long long t_end = now_ns() + (long long) secs * 1000000000LL;
    long long t_next_report = now_ns() + 1000000000LL;
    unsigned long long prev_sent = 0;

    while (now_ns() < t_end) {
        int n = epoll_wait(ep, evs, MAXC, 50);
        for (int i = 0; i < n; i++) {
            int idx = (int) evs[i].data.u64;
            if (idx < 0 || idx >= g_n) continue;
            /*
             * EPOLLET（边缘触发）要求把 socket 读干净，直到 recv 返回 EAGAIN。
             * 早期版本在"缓冲区空了"就 break，导致内核里还有数据但再也不会有事件，
             * 连接随机卡死——表现为某些轮次吞吐腰斩（13k vs 35k），看起来像机器抖动。
             */
            for (;;) {
                ssize_t r = recv(g[idx].fd, g[idx].buf + g[idx].buflen, sizeof g[idx].buf - g[idx].buflen, 0);
                if (r > 0) {
                    g[idx].buflen += (int) r;
                } else if (r == 0) {
                    /* 对端关闭 */
                    epoll_ctl(ep, EPOLL_CTL_DEL, g[idx].fd, NULL);
                    close(g[idx].fd);
                    g[idx].fd = -1;
                    break;
                } else {
                    if (errno == EAGAIN || errno == EWOULDBLOCK) {
                        break; /* 读干净了 */
                    }
                    break;
                }

                while (g[idx].buflen >= FRAME) {
                    long long ts;
                    memcpy(&ts, g[idx].buf + 16, 8);
                    long long rtt = now_ns() - ts;
                    int b = (int) (rtt / 1000 / BUCKET_US);
                    if (b >= 0 && b < MAX_BUCKET) g_hist[b]++;
                    g[idx].recv++;
                    memmove(g[idx].buf, g[idx].buf + FRAME, g[idx].buflen - FRAME);
                    g[idx].buflen -= FRAME;

                    /* closed-loop：立刻发下一条 */
                    long long nts = now_ns();
                    build_frame(out, g[idx].recv, nts);
                    if (send(g[idx].fd, out, FRAME, MSG_NOSIGNAL) == FRAME) {
                        g[idx].send_ns = nts;
                        g[idx].sent++;
                    }
                }
            }
        }

        long long t = now_ns();
        if (t >= t_next_report) {
            unsigned long long sent = 0, recv = 0;
            for (int i = 0; i < g_n; i++) {
                sent += g[i].sent;
                recv += g[i].recv;
            }
            double p50, p99, mx;
            pct(&p50, &p99, &mx);
            printf("conns=%-3d 完成=%-9llu 速率=%-8.0f msg/s  p50=%.2fms p99=%.2fms max=%.2fms\n",
                   g_n, recv, (double) (recv) / ((double) (secs) - 0.0), p50, p99, mx);
            fflush(stdout);
            (void) prev_sent;
            prev_sent = recv;
            t_next_report += 1000000000LL;
        }
    }

    unsigned long long sent = 0, recv = 0;
    for (int i = 0; i < g_n; i++) {
        sent += g[i].sent;
        recv += g[i].recv;
    }
    double p50, p99, mx;
    pct(&p50, &p99, &mx);
    printf("SUMMARY conns=%d sent=%llu recv=%llu rate=%.0f msg/s p50=%.2fms p99=%.2fms max=%.2fms\n",
           g_n, sent, recv, (double) recv / (double) secs, p50, p99, mx);

    for (int i = 0; i < g_n; i++) close(g[i].fd);
    return 0;
}
