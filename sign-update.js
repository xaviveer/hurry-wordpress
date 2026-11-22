#!/usr/bin/env node
/**
 * Signs plugin releases for the self-updater (Ed25519).
 *
 * The private key never lives in this repository nor on the CDN: it is read
 * from HURRRY_UPDATE_KEY, or from ~/.hurrry/update-signing-key.pem. Whoever
 * holds it can ship code to every store, so keep a backup somewhere safe and
 * nowhere else.
 *
 *   node sign-update.js init              create the key if there is none, print the public key
 *   node sign-update.js pub               print the public key (base64, 32 raw bytes)
 *   node sign-update.js sign <message>    print the base64 signature of <message>
 *   node sign-update.js verify <message> <signature> [publicKey]
 */

const crypto = require("crypto");
const fs = require("fs");
const os = require("os");
const path = require("path");

const KEY_FILE = process.env.HURRRY_UPDATE_KEY || path.join(os.homedir(), ".hurrry", "update-signing-key.pem");

// DER prefix of an Ed25519 SubjectPublicKeyInfo; the 32 raw key bytes follow.
const SPKI_PREFIX = Buffer.from("302a300506032b6570032100", "hex");

function fail(msg) {
  process.stderr.write(msg + "\n");
  process.exit(1);
}

function privateKey() {
  if (!fs.existsSync(KEY_FILE)) {
    fail("No signing key at " + KEY_FILE + ". Run: node sign-update.js init");
  }
  return crypto.createPrivateKey(fs.readFileSync(KEY_FILE, "utf8"));
}

function publicKeyB64(priv) {
  const der = crypto.createPublicKey(priv).export({ format: "der", type: "spki" });
  return der.subarray(der.length - 32).toString("base64");
}

function publicKeyFromB64(b64) {
  const raw = Buffer.from(b64, "base64");
  if (raw.length !== 32) fail("The public key must be 32 bytes in base64.");
  return crypto.createPublicKey({ key: Buffer.concat([SPKI_PREFIX, raw]), format: "der", type: "spki" });
}

const [cmd, ...args] = process.argv.slice(2);

if (cmd === "init") {
  if (!fs.existsSync(KEY_FILE)) {
    const pair = crypto.generateKeyPairSync("ed25519");
    fs.mkdirSync(path.dirname(KEY_FILE), { recursive: true });
    fs.writeFileSync(KEY_FILE, pair.privateKey.export({ format: "pem", type: "pkcs8" }), { mode: 0o600, flag: "wx" });
    process.stderr.write("New signing key written to " + KEY_FILE + "\n");
  }
  process.stdout.write(publicKeyB64(privateKey()) + "\n");
} else if (cmd === "pub") {
  process.stdout.write(publicKeyB64(privateKey()) + "\n");
} else if (cmd === "sign" && args.length === 1) {
  process.stdout.write(crypto.sign(null, Buffer.from(args[0], "utf8"), privateKey()).toString("base64") + "\n");
} else if (cmd === "verify" && args.length >= 2) {
  const pub = args[2] ? publicKeyFromB64(args[2]) : crypto.createPublicKey(privateKey());
  const ok = crypto.verify(null, Buffer.from(args[0], "utf8"), pub, Buffer.from(args[1], "base64"));
  process.stdout.write(ok ? "ok\n" : "BAD SIGNATURE\n");
  process.exit(ok ? 0 : 1);
} else {
  fail("Usage: node sign-update.js init | pub | sign <message> | verify <message> <signature> [publicKey]");
}
