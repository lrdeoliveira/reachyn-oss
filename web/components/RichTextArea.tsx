"use client";

import { useEffect, useRef, useState } from "react";

// Mapeia ASCII → unicode estilizado (negrito/itálico sans-serif — cola em LinkedIn/IG/X).
function mapChars(s: string, up: number, lo: number, dig: number) {
  let out = "";
  for (const ch of s) {
    const c = ch.codePointAt(0)!;
    if (c >= 65 && c <= 90) out += String.fromCodePoint(up + c - 65);
    else if (c >= 97 && c <= 122) out += String.fromCodePoint(lo + c - 97);
    else if (dig && c >= 48 && c <= 57) out += String.fromCodePoint(dig + c - 48);
    else out += ch;
  }
  return out;
}
const toBold = (s: string) => mapChars(s, 0x1d5d4, 0x1d5ee, 0x1d7ec);
const toItalic = (s: string) => mapChars(s, 0x1d608, 0x1d622, 0);
function unstyle(s: string) {
  let out = "";
  for (const ch of s) {
    const c = ch.codePointAt(0)!;
    if (c >= 0x1d5d4 && c <= 0x1d5ed) out += String.fromCharCode(65 + c - 0x1d5d4);
    else if (c >= 0x1d5ee && c <= 0x1d607) out += String.fromCharCode(97 + c - 0x1d5ee);
    else if (c >= 0x1d7ec && c <= 0x1d7f5) out += String.fromCharCode(48 + c - 0x1d7ec);
    else if (c >= 0x1d608 && c <= 0x1d621) out += String.fromCharCode(65 + c - 0x1d608);
    else if (c >= 0x1d622 && c <= 0x1d63b) out += String.fromCharCode(97 + c - 0x1d622);
    else out += ch;
  }
  return out;
}
const isStyled = (s: string) => /[\u{1d5d4}-\u{1d63b}\u{1d7ec}-\u{1d7f5}]/u.test(s);

const EMOJIS = ["🔥", "💡", "🚀", "✅", "🎯", "📈", "💼", "📌", "👇", "🙌", "💬", "❤️", "😍", "🤯", "⚡", "🦊", "📣", "🔑", "💸", "🎬", "📸", "🧵", "🤖", "✨", "📊", "🏆", "👀", "💪", "🌟", "🙏"];

export function RichTextArea({ value, onChange, placeholder, limit }: { value: string; onChange: (v: string) => void; placeholder?: string; limit?: number }) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const [emoji, setEmoji] = useState(false);

  // Cresce com o conteúdo (sem scroll interno) pra ler/editar o texto inteiro de uma vez.
  const MIN_H = 130;
  useEffect(() => {
    const el = ref.current;
    if (el) { el.style.height = "auto"; el.style.height = Math.max(MIN_H, el.scrollHeight) + "px"; }
  }, [value]);

  function applyStyle(fn: (s: string) => string) {
    const ta = ref.current; if (!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd;
    if (s === e) return;
    const sel = value.slice(s, e);
    const styled = isStyled(sel) ? unstyle(sel) : fn(sel);
    onChange(value.slice(0, s) + styled + value.slice(e));
  }
  function insert(txt: string) {
    const ta = ref.current; const pos = ta?.selectionStart ?? value.length;
    onChange(value.slice(0, pos) + txt + value.slice(pos));
    setEmoji(false);
  }
  // Lista: prefixa "• " nas linhas selecionadas (ou na linha do cursor). Re-clicar remove.
  function bulletList() {
    const ta = ref.current; if (!ta) return;
    const s = ta.selectionStart, e = ta.selectionEnd;
    const ls = value.lastIndexOf("\n", s - 1) + 1;
    const le = value.indexOf("\n", e); const end = le === -1 ? value.length : le;
    const block = value.slice(ls, end);
    const allBulleted = block.split("\n").every((l) => l.trim() === "" || l.trimStart().startsWith("• "));
    const next = block.split("\n").map((l) => {
      if (l.trim() === "") return l;
      return allBulleted ? l.replace(/^(\s*)• /, "$1") : (l.trimStart().startsWith("• ") ? l : "• " + l);
    }).join("\n");
    onChange(value.slice(0, ls) + next + value.slice(end));
  }

  const over = typeof limit === "number" && limit > 0 && value.length > limit;
  const btn = { background: "var(--bg)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 7, padding: "5px 10px", cursor: "pointer", fontSize: ".9rem" } as const;

  return (
    <div>
      <div style={{ display: "flex", gap: 6, marginBottom: 6, position: "relative", alignItems: "center" }}>
        <button type="button" style={{ ...btn, fontWeight: 800 }} title="Negrito" onMouseDown={(e) => { e.preventDefault(); applyStyle(toBold); }}>B</button>
        <button type="button" style={{ ...btn, fontStyle: "italic" }} title="Itálico" onMouseDown={(e) => { e.preventDefault(); applyStyle(toItalic); }}>I</button>
        <button type="button" style={btn} title="Lista com marcadores" onMouseDown={(e) => { e.preventDefault(); bulletList(); }}>• Lista</button>
        <button type="button" style={btn} title="Inserir hashtag" onMouseDown={(e) => { e.preventDefault(); insert("#"); }}>#</button>
        <button type="button" style={btn} title="Emoji" onMouseDown={(e) => { e.preventDefault(); setEmoji((v) => !v); }}>😊</button>
        {typeof limit === "number" && (
          <span style={{ marginLeft: "auto", fontSize: ".78rem", color: over ? "#ef4444" : "var(--muted)", fontWeight: over ? 700 : 400 }} title={over ? "Passou do limite da plataforma" : "Caracteres"}>
            {value.length}{limit > 0 ? `/${limit}` : ""}
          </span>
        )}
        {emoji && (
          <div style={{ position: "absolute", top: 34, left: 0, zIndex: 10, background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 10, padding: 8, display: "grid", gridTemplateColumns: "repeat(8,1fr)", gap: 4, width: 300, boxShadow: "0 10px 30px rgba(0,0,0,.4)" }}>
            {EMOJIS.map((em) => <button key={em} type="button" onMouseDown={(e) => { e.preventDefault(); insert(em); }} style={{ background: "none", border: 0, cursor: "pointer", fontSize: "1.2rem", padding: 3 }}>{em}</button>)}
          </div>
        )}
      </div>
      <textarea ref={ref} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder}
        style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid " + (over ? "#ef4444" : "var(--line)"), borderRadius: 10, padding: "12px 14px", fontSize: ".95rem", width: "100%", minHeight: MIN_H, overflow: "hidden", resize: "none" }} />
    </div>
  );
}
