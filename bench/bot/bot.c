// TSF 压测端（open-loop）：N 个连接，每个连接按固定速率发帧。
//
// 和 load_tsf 的区别：
//   load_tsf  是 closed-loop（收到回包立刻发下一条）—— 测的是"最大吞吐"
//   本程序    是 open-loop（每 100ms 发一条）—— 测的是"1 万连接 @10Hz 这种真实负载下
//             服务端扛不扛得住"，包括它扛不住时会不会堆积。
//
// 用法: ./bot <host> <port> <conns> <rate_hz> <seconds>
// 输出: sent / recv / 实际速率 / RTT 分布 / 背压跳过次数

#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/epoll.h>
#include <sys/resource.h>
#include <sys/socket.h>
#include <time.h>
#include <unistd.h>

#define MAXC 20000
#define FRAME 24          /* 8 字节头 + 16 字节 body(seq+ts) */
#define BODY_OFF 8
#define TS_OFF 16
#define BUCKET_US 10
#define MAX_BUCKET 20000  /* 10us * 20000 = 200ms */
static int g_window = 32; /* 每连接最多 N 条在途，超出就跳过（模拟客户端限流）；
                             命令行第 6 个参数可覆盖。窗口太小会把压测端自己卡住，
                             测出来的"吞吐"其实是窗口×RTT 的函数，不是服务端能力。 */

typedef struct {
    int fd;
    long long next_ns;
    unsigned long long sent;
    unsigned long long recv;
    unsigned int outstanding;
    int buflen;
    unsigned char buf[FRAME * 8];
} conn_t;

static conn_t g[MAXC];
static int g_n = 0;
static unsigned long long g_hist[MAX_BUCKET];
static unsigned long long g_skip = 0;
static unsigned long long g_eagain = 0;
static unsigned long long g_err = 0;
static int g_badconn = 0;
static int g_connect_fail = 0;
static int g_connect_errno = 0;
static int g_bind_fail = 0;
static int g_bind_errno = 0;
static int g_send_errno = 0;

static long long now_ns(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (long long) ts.tv_sec * 1000000000LL + ts.tv_nsec;
}

static void build_frame(unsigned char *o, long long seq, long long ts)
{
    unsigned int len = FRAME;
    o[0] = (unsigned char) ((len >> 24) & 0xFF);
    o[1] = (unsigned char) ((len >> 16) & 0xFF);
    o[2] = (unsigned char) ((len >> 8) & 0xFF);
    o[3] = (unsigned char) (len & 0xFF);
    o[4] = (unsigned char) ((1001 >> 8) & 0xFF); /* msgId = 1001 */
    o[5] = (unsigned char) (1001 & 0xFF);
    o[6] = 0;
    o[7] = 0;
    memcpy(o + BODY_OFF, &seq, 8);
    memcpy(o + TS_OFF, &ts, 8);
}

static void on_read(int i)
{
    conn_t *c = &g[i];
    while (1) {
        ssize_t n = recv(c->fd, c->buf + c->buflen, sizeof(c->buf) - (size_t) c->buflen, 0);
        if (n <= 0) {
            break;
        }
        c->buflen += (int) n;
        while (c->buflen >= FRAME) {
            unsigned int len = ((unsigned int) c->buf[0] << 24) | ((unsigned int) c->buf[1] << 16)
                             | ((unsigned int) c->buf[2] << 8) | (unsigned int) c->buf[3];
            if (len != FRAME) {
                c->buflen = 0; /* 不认识的帧（不该出现），丢掉避免死循环 */
                break;
            }
            long long ts;
            memcpy(&ts, c->buf + TS_OFF, 8);
            long long rtt = now_ns() - ts;
            if (rtt > 0) {
                long long b = rtt / (BUCKET_US * 1000LL);
                if (b < MAX_BUCKET) {
                    g_hist[b]++;
                }
            }
            c->recv++;
            if (c->outstanding > 0) {
                c->outstanding--;
            }
            memmove(c->buf, c->buf + FRAME, (size_t) (c->buflen - FRAME));
            c->buflen -= FRAME;
        }
    }
}

static void pct(double *p50, double *p99, double *mx)
{
    unsigned long long total = 0;
    for (int i = 0; i < MAX_BUCKET; i++) {
        total += g_hist[i];
    }
    *p50 = *p99 = *mx = 0;
    if (!total) {
        return;
    }
    unsigned long long acc = 0;
    int got50 = 0, got99 = 0;
    for (int i = 0; i < MAX_BUCKET; i++) {
        if (!g_hist[i]) {
            continue;
        }
        acc += g_hist[i];
        if (!got50 && acc >= total * 0.50) {
            *p50 = i * BUCKET_US / 1000.0;
            got50 = 1;
        }
        if (!got99 && acc >= total * 0.99) {
            *p99 = i * BUCKET_US / 1000.0;
            got99 = 1;
        }
        *mx = i * BUCKET_US / 1000.0;
    }
}

int main(int argc, char **argv)
{
    if (argc < 6) {
        fprintf(stderr, "usage: %s host port conns rate_hz seconds\n", argv[0]);
        return 1;
    }
    const char *host = argv[1];
    int port = atoi(argv[2]);
    int want = atoi(argv[3]);
    int rate = atoi(argv[4]);
    int secs = atoi(argv[5]);
    if (argc > 6) {
        int w = atoi(argv[6]);
        if (w > 0) {
            g_window = w;
        }
    }
    if (want > MAXC) {
        want = MAXC;
    }
    if (rate <= 0 || secs <= 0) {
        fprintf(stderr, "rate/seconds 必须 > 0\n");
        return 1;
    }

    struct rlimit rl;
    if (getrlimit(RLIMIT_NOFILE, &rl) == 0) {
        rl.rlim_cur = rl.rlim_max;
        setrlimit(RLIMIT_NOFILE, &rl);
    }

    struct sockaddr_in sa;
    memset(&sa, 0, sizeof sa);
    sa.sin_family = AF_INET;
    sa.sin_port = htons((unsigned short) port);
    inet_pton(AF_INET, host, &sa.sin_addr);

    int ep = epoll_create1(0);
    struct epoll_event *evs = calloc(256, sizeof *evs);

    /* 分批建连：一次性 1 万次 connect 会把 listen backlog 打爆，
       服务端会丢 SYN，客户端看到的错误是"连上了但立刻断" */
    long long conn_start = now_ns();
    for (int i = 0; i < want; i++) {
        int fd = socket(AF_INET, SOCK_STREAM | SOCK_NONBLOCK, 0);
        if (fd < 0) {
            fprintf(stderr, "socket 失败于第 %d 个: %s\n", i, strerror(errno));
            break;
        }
        int one = 1;
        setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof one);

        /* 关键：显式绑定源端口。
           本机 ip_local_port_range 只有 4096 个端口（44620-48715），
           全交给内核自动分配的话，超过 4096 条连接就 EADDRNOTAVAIL——
           之前"1 万连接只跑通 4096 条"根本不是服务端的限制。
           而**显式** bind 不受 ip_local_port_range 约束（只要端口空闲且 ≥1024），
           从 20000 起顺序取，1 万条绰绰有余。
           别改绑 127.0.0.2 之类的 loopback 别名：WSL 上每次 connect 会慢到 24ms。 */
        if (getenv("BOT_NOBIND") == NULL) {
            struct sockaddr_in la;
            memset(&la, 0, sizeof la);
            la.sin_family = AF_INET;
            la.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
            la.sin_port = htons((unsigned short) (20000 + (i % 40000)));
            if (bind(fd, (struct sockaddr *) &la, sizeof la) != 0) {
                g_bind_fail++;
                if (!g_bind_errno) {
                    g_bind_errno = errno;
                }
            }
            /* 主动 RST 关闭：避免 TIME_WAIT 占住源端口影响下一轮 */
            struct linger lg;
            lg.l_onoff = 1;
            lg.l_linger = 0;
            setsockopt(fd, SOL_SOCKET, SO_LINGER, &lg, sizeof lg);
        }

        if (connect(fd, (struct sockaddr *) &sa, sizeof sa) != 0 && errno != EINPROGRESS) {
            g_connect_fail++;
            if (!g_connect_errno) {
                g_connect_errno = errno;
            }
        }
        struct epoll_event ev;
        memset(&ev, 0, sizeof ev);
        ev.events = EPOLLIN | EPOLLET;
        ev.data.u64 = (unsigned long long) g_n;
        epoll_ctl(ep, EPOLL_CTL_ADD, fd, &ev);
        g[g_n].fd = fd;
        g[g_n].buflen = 0;
        g[g_n].sent = 0;
        g[g_n].recv = 0;
        g[g_n].outstanding = 0;
        g[g_n].next_ns = 0;
        g_n++;
        if (g_n % 200 == 0) {
            usleep(20000); /* 20ms 一批：给服务端 accept 队列喘息，过快会触发 RST */
        }
    }
    printf("建连阶段耗时: %.2f 秒（%d 条，%.0f 条/秒）\n",
           (double) (now_ns() - conn_start) / 1e9, g_n,
           (double) g_n / (((double) (now_ns() - conn_start)) / 1e9));
    usleep(300000);

    /* 建连校验：非阻塞 connect 的失败不会在 connect() 返回时暴露，
       要等 300ms 后用 SO_ERROR 问。1 万连接同时建连时，listen backlog
       和 accept 速度都会导致一部分连接其实没建立成功——如果不查，
       它们会在发送阶段表现为"永远 EAGAIN"，很容易被误读成服务端吞吐不足。 */
    for (int i = 0; i < g_n; i++) {
        int soerr = 0;
        socklen_t sl = sizeof soerr;
        if (getsockopt(g[i].fd, SOL_SOCKET, SO_ERROR, &soerr, &sl) != 0 || soerr != 0) {
            g_badconn++;
        }
    }

    long long period = 1000000000LL / (long long) rate;
    long long start = now_ns();
    /* 相位错开：如果所有连接都在同一瞬间发，服务端收到的就是一串"齐射"，
       RTT 会变成 = 连接数 × 单条处理时间，测出来是 bot 的行为不是服务端的能力。
       真实客户端是有抖动的，这里用 i*period/n 把它们均匀铺在一个周期内。 */
    for (int i = 0; i < g_n; i++) {
        g[i].next_ns = start + (long long) i * period / (long long) (g_n > 0 ? g_n : 1);
    }
    long long deadline = start + (long long) secs * 1000000000LL;

    unsigned char out[FRAME];
    while (1) {
        long long now = now_ns();
        if (now >= deadline) {
            break;
        }

        int n = epoll_wait(ep, evs, 256, 1);
        for (int k = 0; k < n; k++) {
            on_read((int) evs[k].data.u64);
        }

        now = now_ns();
        for (int i = 0; i < g_n; i++) {
            if (g[i].next_ns > now) {
                continue;
            }
            if (g[i].outstanding >= (unsigned int) g_window) {
                g_skip++;
            } else {
                build_frame(out, g[i].sent, now);
                ssize_t w = send(g[i].fd, out, FRAME, MSG_NOSIGNAL);
                if (w == FRAME) {
                    g[i].sent++;
                    g[i].outstanding++;
                } else if (w < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
                    /* 发不出去说明服务端处理不过来、TCP 缓冲已满。
                       不计数的话，报告上会显示"发了 41k 收到 41k，一切正常"，
                       实际是丢了 59k —— 比报错更有害。 */
                    g_eagain++;
                } else {
                    g_err++; /* 连接坏了（对端关闭/重置），和"慢"要分开看 */
                    if (!g_send_errno) {
                        g_send_errno = errno;
                    }
                }
            }
            g[i].next_ns += period;
            /* 落后超过 10 个周期就重置，否则会一次性补发一串 */
            if (g[i].next_ns < now - period * 10) {
                g[i].next_ns = now + period;
            }
        }
    }

    /* 收尾：把还在路上的回包读掉 */
    long long grace = now_ns() + 500000000LL;
    while (now_ns() < grace) {
        int n = epoll_wait(ep, evs, 256, 20);
        if (n == 0) {
            break;
        }
        for (int k = 0; k < n; k++) {
            on_read((int) evs[k].data.u64);
        }
    }

    unsigned long long sent = 0, recv_n = 0;
    for (int i = 0; i < g_n; i++) {
        sent += g[i].sent;
        recv_n += g[i].recv;
    }
    double p50, p99, mx;
    pct(&p50, &p99, &mx);

    printf("conns=%d rate=%d target=%lld msg/s  建连失败=%d(connect 立即失败 %d, errno=%d %s)  握手后失败=%d\n",
           g_n, rate, (long long) g_n * rate, g_connect_fail + g_badconn,
           g_connect_fail, g_connect_errno, g_connect_errno ? strerror(g_connect_errno) : "-",
           g_badconn);
    printf("实测时长: %.2f 秒（设定 %d 秒）\n", (double) (now_ns() - start) / 1e9, secs);

    /* 压测端自身的 CPU：不报这个数就无法判断"服务端只跑到 5 万"到底是
       服务端的上限，还是压测端自己跑满了。 */
    struct rusage ru;
    if (getrusage(RUSAGE_SELF, &ru) == 0) {
        double cpu = (double) ru.ru_utime.tv_sec + (double) ru.ru_stime.tv_sec
                   + (double) (ru.ru_utime.tv_usec + ru.ru_stime.tv_usec) / 1e6;
        printf("压测端 CPU: %.2f 秒 / %d 秒 = %.0f%% 单核%s\n",
               cpu, secs, cpu / (double) secs * 100.0,
               (cpu / (double) secs > 0.85) ? "（接近 100%，瓶颈可能在压测端）" : "");
    }

    printf("sent=%llu recv=%llu achieved=%.0f msg/s\n",
           sent, recv_n, (double) recv_n / (double) secs);
    printf("rtt p50=%.2f ms p99=%.2f ms max=%.2f ms\n", p50, p99, mx);
    printf("skip(在途超限)=%llu  EAGAIN(缓冲满)=%llu  连接错误=%llu  未收回=%lld\n",
           g_skip, g_eagain, g_err, (long long) (sent - recv_n));
    printf("bind 失败=%d(errno=%d %s)  send 首个错误=%d %s\n",
           g_bind_fail, g_bind_errno, g_bind_errno ? strerror(g_bind_errno) : "-",
           g_send_errno, g_send_errno ? strerror(g_send_errno) : "-");
    long long target = (long long) g_n * rate * secs;
    if (target > 0) {
        printf("目标发送=%lld 实际发出=%llu (%.1f%%)\n",
               target, sent, (double) sent / (double) target * 100.0);
    }
    return 0;
}
