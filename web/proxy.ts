import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

const HTML_CACHE = "private, no-cache, no-store, max-age=0, must-revalidate";

export function proxy(_request: NextRequest) {
  const res = NextResponse.next();
  res.headers.set("Cache-Control", HTML_CACHE);
  return res;
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|icon.png|apple-icon.png|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico|html)$).*)"],
};
