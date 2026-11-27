/**
 * 用 node 扮演浏览器，验证 web/index.html 的收发逻辑。
 *
 * 为什么需要它：网页端在 WSL 里没有浏览器可以点，但"帧编解码 + 登录 + 列表"
 * 这段逻辑和 index.html 里是同一份代码（buildFrame / drainFrames 原样抄过来）。
 * 这个脚本能通，说明前端至少协议层是好的；剩下的只是 DOM 交互。
 *
 *   node tools/ws-smoke.js [port]
 *
 * 需要服务在跑（./run-web.sh 或 ./ddz_hall）。
 */

const PORT = parseInt(process.argv[2] || "9061", 10);
const URL_WS = "ws://127.0.0.1:" + PORT + "/";

// ---- 和 index.html 完全一致的帧编解码 ----
function buildFrame(msgId, body) {
  const bodyBytes = new TextEncoder().encode(body);
  const len = 8 + bodyBytes.length;
  const buf = new Uint8Array(len);
  buf[0] = (len >>> 24) & 0xff;
  buf[1] = (len >>> 16) & 0xff;
  buf[2] = (len >>> 8) & 0xff;
  buf[3] = len & 0xff;
  buf[4] = (msgId >>> 8) & 0xff;
  buf[5] = msgId & 0xff;
  buf[6] = 0;
  buf[7] = 0;
  buf.set(bodyBytes, 8);
  return buf;
}

const decoder = new TextDecoder();
let recvBuf = new Uint8Array(0);

function drainFrames() {
  const out = [];
  while (recvBuf.length >= 8) {
    const len = (recvBuf[0] << 24) | (recvBuf[1] << 16) | (recvBuf[2] << 8) | recvBuf[3];
    if (len < 8 || len > 1024 * 1024) {
      recvBuf = new Uint8Array(0);
      break;
    }
    if (recvBuf.length < len) break;
    const msgId = (recvBuf[4] << 8) | recvBuf[5];
    const body = decoder.decode(recvBuf.subarray(8, len));
    out.push({ msgId, body });
    recvBuf = recvBuf.subarray(len);
  }
  return out;
}

// ---- 流程 ----
const MSG_LOGIN = 1, MSG_LOGIN_OK = 2;
const LIST_REQ = 1001, LIST_RESP = 1002;

console.log("连接 " + URL_WS);
const ws = new WebSocket(URL_WS);
ws.binaryType = "arraybuffer";

let uid = 0;
let done = false;

const timer = setTimeout(() => {
  if (!done) {
    console.error("FAIL 超时");
    process.exit(1);
  }
}, 6000);

ws.onopen = () => {
  console.log("已连接，发登录帧");
  ws.send(buildFrame(MSG_LOGIN, "tok-a"));
};

ws.onerror = (e) => {
  console.error("FAIL 连接出错");
  process.exit(1);
};

ws.onmessage = (ev) => {
  const chunk = new Uint8Array(ev.data);
  const merged = new Uint8Array(recvBuf.length + chunk.length);
  merged.set(recvBuf, 0);
  merged.set(chunk, recvBuf.length);
  recvBuf = merged;

  for (const f of drainFrames()) {
    if (f.msgId === MSG_LOGIN_OK) {
      uid = parseInt(f.body, 10) || 0;
      console.log("PASS 登录成功 uid=" + uid);
      ws.send(buildFrame(LIST_REQ, ""));
    } else if (f.msgId === LIST_RESP) {
      const rooms = (JSON.parse(f.body || "{}").rooms) || [];
      console.log("PASS 收到房间列表，共 " + rooms.length + " 个");
      done = true;
      clearTimeout(timer);
      ws.close();
      console.log("OK 前端收发逻辑可用（帧编解码 / 登录 / 列表）");
      process.exit(0);
    } else {
      console.log("收到 msgId=" + f.msgId + " body=" + f.body.slice(0, 60));
    }
  }
};
