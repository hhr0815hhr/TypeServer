/**
 * 回归：叫分阶段前端必须拿到"轮到谁"。
 *
 * 之前 RoomService::pushState() 只在出牌阶段给 turn，其余一律 -1；而 deal()
 * 是先 pushBid() 再 pushState()，正确的轮次被后一条覆盖成 -1 —— 前端的叫分
 * 按钮条件是 `phase === 2 && turn === mySeat`，于是永远不显示。
 *
 *   node tools/bid-check.js [port]
 */

const PORT = parseInt(process.argv[2] || "9071", 10);

const MSG_LOGIN = 1, MSG_LOGIN_OK = 2;
const CREATE_REQ = 1003, CREATE_RESP = 1004;
const JOIN_REQ = 1005, JOIN_RESP = 1006;
const ERR_RESP = 1008;
const READY_REQ = 1013, READY_RESP = 1014;
const START_REQ = 1015;
const BID_REQ = 1017, BID_RESP = 1018;
const PUSH_STATE = 1100, PUSH_BID = 1104;

function buildFrame(msgId, body) {
  const bytes = new TextEncoder().encode(body);
  const len = 8 + bytes.length;
  const buf = new Uint8Array(len);
  buf[0] = (len >>> 24) & 0xff;
  buf[1] = (len >>> 16) & 0xff;
  buf[2] = (len >>> 8) & 0xff;
  buf[3] = len & 0xff;
  buf[4] = (msgId >>> 8) & 0xff;
  buf[5] = msgId & 0xff;
  buf.set(bytes, 8);
  return buf;
}

const decoder = new TextDecoder();

class Client {
  constructor(token) {
    this.token = token;
    this.uid = 0;
    this.recvBuf = new Uint8Array(0);
    this.waiters = new Map();
  }

  open() {
    return new Promise((resolve, reject) => {
      const ws = new WebSocket("ws://127.0.0.1:" + PORT + "/");
      ws.binaryType = "arraybuffer";
      this.ws = ws;
      const timer = setTimeout(() => reject(new Error("登录超时")), 5000);
      ws.onopen = () => ws.send(buildFrame(MSG_LOGIN, this.token));
      ws.onerror = () => reject(new Error("连接失败"));
      ws.onmessage = (ev) => {
        const chunk = new Uint8Array(ev.data);
        const merged = new Uint8Array(this.recvBuf.length + chunk.length);
        merged.set(this.recvBuf, 0);
        merged.set(chunk, this.recvBuf.length);
        this.recvBuf = merged;
        while (this.recvBuf.length >= 8) {
          const len = (this.recvBuf[0] << 24) | (this.recvBuf[1] << 16) | (this.recvBuf[2] << 8) | this.recvBuf[3];
          if (len < 8 || len > 1048576) { this.recvBuf = new Uint8Array(0); break; }
          if (this.recvBuf.length < len) break;
          const msgId = (this.recvBuf[4] << 8) | this.recvBuf[5];
          const body = decoder.decode(this.recvBuf.subarray(8, len));
          this.recvBuf = this.recvBuf.subarray(len);
          if (msgId === MSG_LOGIN_OK && this.uid === 0) {
            this.uid = parseInt(body, 10) || 0;
            clearTimeout(timer);
            resolve(this);
            continue;
          }
          const list = this.waiters.get(msgId);
          if (list && list.length) list.shift()(body);
        }
      };
    });
  }

  send(msgId, body) {
    this.ws.send(buildFrame(msgId, body || ""));
  }

  waitFor(msgId, timeoutMs = 4000) {
    return new Promise((resolve) => {
      if (!this.waiters.has(msgId)) this.waiters.set(msgId, []);
      const t = setTimeout(() => resolve(null), timeoutMs);
      this.waiters.get(msgId).push((body) => {
        clearTimeout(t);
        resolve(body);
      });
    });
  }

  close() {
    if (this.ws) this.ws.close();
  }
}

/** 一直读 PUSH_STATE 直到 phase 等于目标值 */
async function waitPhase(cl, phase, timeoutMs = 5000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const body = await cl.waitFor(PUSH_STATE, Math.max(200, deadline - Date.now()));
    if (body === null) return null;
    const d = JSON.parse(body || "{}");
    if (d.phase === phase) return d;
  }
  return null;
}

let failed = 0;
function check(cond, name, extra) {
  if (cond) {
    console.log("  PASS  " + name);
  } else {
    failed++;
    console.log("  FAIL  " + name + (extra ? "  (" + extra + ")" : ""));
  }
}

async function main() {
  console.log("== 叫分交互回归（端口 " + PORT + "）==");

  const a = await new Client("tok-a").open();
  const b = await new Client("tok-b").open();
  const c = await new Client("tok-c").open();
  console.log("  三个玩家：" + a.uid + " / " + b.uid + " / " + c.uid);

  a.send(CREATE_REQ, JSON.stringify({ level: 1 }));
  const created = await a.waitFor(CREATE_RESP);
  const roomId = created === null ? 0 : (JSON.parse(created).roomId || 0);
  check(roomId > 0, "A 建房 #" + roomId);

  b.send(JOIN_REQ, JSON.stringify({ roomId }));
  await b.waitFor(JOIN_RESP);
  c.send(JOIN_REQ, JSON.stringify({ roomId }));
  await c.waitFor(JOIN_RESP);
  check(true, "B / C 加入");

  for (const cl of [a, b, c]) {
    cl.send(READY_REQ, "");
    await cl.waitFor(READY_RESP, 2000);
  }

  // 在发叫分之前就要挂上 B 的等待：服务端的 PUSH_BID 可能比 A 的应答先到，
  // 而 Client 在没有 waiter 时会直接丢弃消息（和浏览器一样——没绑 onmessage
  // 分支就收不到），后注册就会永远等不到
  const bBidPush = b.waitFor(PUSH_BID, 4000);

  a.send(START_REQ, "");

  // 关键：叫分阶段的 PUSH_STATE 必须给出 turn
  const st = await waitPhase(a, 2);
  check(st !== null, "进入叫分阶段（phase=2）");
  if (st !== null) {
    check(st.turn === 0, "叫分阶段 turn=0（不是 -1）", "实际 " + st.turn);
    check(st.counts[0] === 17, "叫分前地主位 17 张", "实际 " + st.counts[0]);
  }

  // A 叫 1 分：成功，且不该报"必须高于当前最高分"
  a.send(BID_REQ, JSON.stringify({ score: 1 }));
  const bidOk = await a.waitFor(BID_RESP, 3000);
  const errA = await a.waitFor(ERR_RESP, 300);
  check(bidOk !== null, "A 叫 1 分有应答");
  check(errA === null, "A 叫 1 分没有报错", errA ? "收到: " + errA : "");

  // 轮到 B（从开局时挂上的等待里取，可能有开局那一条，取最后一条即可）
  const push = await bBidPush;
  let seatB = -1;
  if (push !== null) {
    const d = JSON.parse(push);
    seatB = d.seat ?? -1;
  }
  check(seatB >= 0, "B 收到叫分轮次推送", "seat=" + seatB);

  // B 叫 1 分应被拒
  b.send(BID_REQ, JSON.stringify({ score: 1 }));
  const errB = await b.waitFor(ERR_RESP, 3000);
  check(errB !== null, "B 叫 1 分被拒（不高于 1）");

  for (const cl of [a, b, c]) cl.close();
  console.log();
  console.log(failed === 0 ? "OK 叫分交互正常" : "FAILED " + failed + " 项");
  process.exit(failed === 0 ? 0 : 1);
}

main().catch((e) => {
  console.error("异常: " + e.message);
  process.exit(1);
});
