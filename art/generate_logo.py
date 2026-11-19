#!/usr/bin/env python3
"""
VSL Brand Logo Generator (Zero external dependencies)
Generates precision geometric SVG marks, app icon, horizontal lockup,
and anti-aliased 1024x1024 PNG renders via a pure-Python analytical SDF rasterizer.
"""

from __future__ import annotations

import math
import shutil
import struct
import subprocess
import zlib
from pathlib import Path

OUT_DIR = Path(__file__).resolve().parent


# ---------------------------------------------------------------------------
# Parametric Logo Geometry (512x512 canonical coordinate space)
# ---------------------------------------------------------------------------
# Visual center offset so the portal + top-right beacon + signal arcs sit balanced at (256, 256)
DX = -12.0
DY = 12.0

# Portal C-bracket centerline bounds
P_LEFT = 100.0 + DX
P_RIGHT = 372.0 + DX
P_TOP = 136.0 + DY
P_BOTTOM = 408.0 + DY
P_RADIUS = 54.0
P_STROKE = 48.0

# Portal openings (symmetric 128px from top-right corner (P_RIGHT, P_TOP))
P_TOP_END_X = P_RIGHT - 128.0
P_RIGHT_START_Y = P_TOP + 128.0

# Worktree graph & live beacon
# Circular fillet math:
#   From ROOT (ROOT_X, ROOT_Y), branch leaves vertically upward at angle -90 deg
#   along a circular arc of radius FILLET_R centered at (ROOT_X + FILLET_R, ROOT_Y)
#   sweeping 45 deg clockwise to tangent angle -45 deg, then straight along the 45 deg ray
#   to BEACON (BEACON_X, BEACON_Y).
FILLET_R = 72.0
ROOT_X = 192.0 + DX
ROOT_Y = 334.0 + DY
FILLET_CX = ROOT_X + FILLET_R
FILLET_CY = ROOT_Y

INV_SQRT2 = 1.0 / math.sqrt(2.0)
FILLET_END_X = FILLET_CX - FILLET_R * INV_SQRT2
FILLET_END_Y = FILLET_CY - FILLET_R * INV_SQRT2

# Project straight along +1, -1 (45 deg) to align BEACON cleanly with P_TOP / P_RIGHT corner
RAY_T = P_RIGHT - FILLET_END_X
BEACON_X = FILLET_END_X + RAY_T  # == P_RIGHT
BEACON_Y = FILLET_END_Y - RAY_T

TRUNK_TOP_X = ROOT_X
TRUNK_TOP_Y = 208.0 + DY

BRANCH_STROKE = 34.0
ROOT_OUTER_R = 30.0
ROOT_INNER_R = 11.0
TRUNK_NODE_R = 23.0
BEACON_CORE_R = 32.0
SIGNAL_ARC_R = 64.0
SIGNAL_ARC_STROKE = 19.0
SIGNAL_ARC2_R = 92.0
SIGNAL_ARC2_STROKE = 15.0


def portal_svg_path() -> str:
    r = P_RADIUS
    return (
        f"M {P_RIGHT:.2f} {P_RIGHT_START_Y:.2f} "
        f"L {P_RIGHT:.2f} {P_BOTTOM - r:.2f} "
        f"A {r:.2f} {r:.2f} 0 0 1 {P_RIGHT - r:.2f} {P_BOTTOM:.2f} "
        f"L {P_LEFT + r:.2f} {P_BOTTOM:.2f} "
        f"A {r:.2f} {r:.2f} 0 0 1 {P_LEFT:.2f} {P_BOTTOM - r:.2f} "
        f"L {P_LEFT:.2f} {P_TOP + r:.2f} "
        f"A {r:.2f} {r:.2f} 0 0 1 {P_LEFT + r:.2f} {P_TOP:.2f} "
        f"L {P_TOP_END_X:.2f} {P_TOP:.2f}"
    )


def arc_svg_path(cx: float, cy: float, r: float, deg_start: float, deg_end: float) -> str:
    a1 = math.radians(deg_start)
    a2 = math.radians(deg_end)
    x1, y1 = cx + r * math.cos(a1), cy + r * math.sin(a1)
    x2, y2 = cx + r * math.cos(a2), cy + r * math.sin(a2)
    return f"M {x1:.2f} {y1:.2f} A {r:.2f} {r:.2f} 0 0 1 {x2:.2f} {y2:.2f}"


def branch_svg_path() -> str:
    return (
        f"M {ROOT_X:.2f} {ROOT_Y:.2f} "
        f"A {FILLET_R:.2f} {FILLET_R:.2f} 0 0 1 {FILLET_END_X:.2f} {FILLET_END_Y:.2f} "
        f"L {BEACON_X:.2f} {BEACON_Y:.2f}"
    )


def theme_palette(theme: str) -> dict[str, str]:
    if theme == "dark":
        return {
            "portal1": "#FFFFFF",
            "portal2": "#C7D2FE",
            "trunk1": "#6366F1",
            "trunk2": "#A78BFA",
            "branch1": "#10B981",
            "branch2": "#22D3EE",
            "beacon1": "#34D399",
            "beacon2": "#38BDF8",
            "spark": "#FFFFFF",
        }
    return {
        "portal1": "#0F172A",
        "portal2": "#312E81",
        "trunk1": "#4F46E5",
        "trunk2": "#7C3AED",
        "branch1": "#059669",
        "branch2": "#0284C7",
        "beacon1": "#10B981",
        "beacon2": "#0EA5E9",
        "spark": "#FFFFFF",
    }


def build_emblem_group(theme: str = "dark", id_prefix: str = "g") -> tuple[str, str]:
    """Return (defs_xml, group_xml) for the standalone emblem in 512x512 space."""
    pal = theme_palette(theme)

    defs = f"""
    <linearGradient id="{id_prefix}-portal" x1="80" y1="420" x2="360" y2="120" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="{pal['portal1']}"/>
      <stop offset="100%" stop-color="{pal['portal2']}"/>
    </linearGradient>
    <linearGradient id="{id_prefix}-trunk" x1="{ROOT_X:.0f}" y1="{ROOT_Y:.0f}" x2="{TRUNK_TOP_X:.0f}" y2="{TRUNK_TOP_Y:.0f}" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="{pal['trunk1']}"/>
      <stop offset="100%" stop-color="{pal['trunk2']}"/>
    </linearGradient>
    <linearGradient id="{id_prefix}-branch" x1="{ROOT_X:.0f}" y1="{ROOT_Y:.0f}" x2="{BEACON_X:.0f}" y2="{BEACON_Y:.0f}" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="{pal['branch1']}"/>
      <stop offset="100%" stop-color="{pal['branch2']}"/>
    </linearGradient>
    <linearGradient id="{id_prefix}-beacon" x1="{BEACON_X - 40:.0f}" y1="{BEACON_Y + 40:.0f}" x2="{BEACON_X + 80:.0f}" y2="{BEACON_Y - 80:.0f}" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="{pal['beacon1']}"/>
      <stop offset="100%" stop-color="{pal['beacon2']}"/>
    </linearGradient>
    <mask id="{id_prefix}-root-mask">
      <rect x="0" y="0" width="512" height="512" fill="white"/>
      <circle cx="{ROOT_X:.2f}" cy="{ROOT_Y:.2f}" r="{ROOT_INNER_R:.2f}" fill="black"/>
    </mask>
    """

    group = f"""
    <g id="{id_prefix}-emblem">
      <!-- Outer Worktree Sandbox Portal -->
      <path d="{portal_svg_path()}"
            fill="none"
            stroke="url(#{id_prefix}-portal)"
            stroke-width="{P_STROKE:.1f}"
            stroke-linecap="round"
            stroke-linejoin="round"/>

      <!-- Parallel Worktree Stem (Trunk + Isolated Node) -->
      <g mask="url(#{id_prefix}-root-mask)">
        <line x1="{ROOT_X:.2f}" y1="{ROOT_Y:.2f}"
              x2="{TRUNK_TOP_X:.2f}" y2="{TRUNK_TOP_Y:.2f}"
              stroke="url(#{id_prefix}-trunk)"
              stroke-width="{BRANCH_STROKE:.1f}"
              stroke-linecap="round"/>
        <circle cx="{TRUNK_TOP_X:.2f}" cy="{TRUNK_TOP_Y:.2f}" r="{TRUNK_NODE_R:.1f}"
                fill="url(#{id_prefix}-trunk)"/>

        <!-- Live Preview Branch Launching Through Portal -->
        <path d="{branch_svg_path()}"
              fill="none"
              stroke="url(#{id_prefix}-branch)"
              stroke-width="{BRANCH_STROKE:.1f}"
              stroke-linecap="round"
              stroke-linejoin="round"/>

        <!-- Root Node Ring -->
        <circle cx="{ROOT_X:.2f}" cy="{ROOT_Y:.2f}" r="{ROOT_OUTER_R:.1f}"
                fill="url(#{id_prefix}-branch)"/>
      </g>

      <!-- Live HTTPS Beacon Node -->
      <circle cx="{BEACON_X:.2f}" cy="{BEACON_Y:.2f}" r="{BEACON_CORE_R:.1f}"
              fill="url(#{id_prefix}-beacon)"/>
      <circle cx="{BEACON_X:.2f}" cy="{BEACON_Y:.2f}" r="11.0"
              fill="{pal['spark']}"/>

      <!-- Live Broadcast Signal Arcs -->
      <path d="{arc_svg_path(BEACON_X, BEACON_Y, SIGNAL_ARC_R, -78, -12)}"
            fill="none"
            stroke="url(#{id_prefix}-beacon)"
            stroke-width="{SIGNAL_ARC_STROKE:.1f}"
            stroke-linecap="round"/>
      <path d="{arc_svg_path(BEACON_X, BEACON_Y, SIGNAL_ARC2_R, -72, -18)}"
            fill="none"
            stroke="url(#{id_prefix}-beacon)"
            stroke-width="{SIGNAL_ARC2_STROKE:.1f}"
            stroke-linecap="round"
            opacity="0.58"/>
    </g>
    """
    return defs, group


def write_svgs() -> list[Path]:
    written: list[Path] = []

    defs, group = build_emblem_group("dark", "vsl-dark")
    p1 = OUT_DIR / "vsl-mark.svg"
    p1.write_text(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" fill="none">\n'
        f'  <defs>{defs}</defs>\n  {group}\n</svg>\n',
        encoding="utf-8",
    )
    written.append(p1)

    defs_l, group_l = build_emblem_group("light", "vsl-light")
    p2 = OUT_DIR / "vsl-mark-light.svg"
    p2.write_text(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" fill="none">\n'
        f'  <defs>{defs_l}</defs>\n  {group_l}\n</svg>\n',
        encoding="utf-8",
    )
    written.append(p2)

    defs_i, group_i = build_emblem_group("dark", "vsl-icon")
    p3 = OUT_DIR / "vsl-icon.svg"
    p3.write_text(
        f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" fill="none">
  <defs>
    <linearGradient id="bg-squircle" x1="40" y1="20" x2="472" y2="492" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#1E1B4B"/>
      <stop offset="52%" stop-color="#0F172A"/>
      <stop offset="100%" stop-color="#060B16"/>
    </linearGradient>
    <radialGradient id="bg-glow" cx="380" cy="130" r="270" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#22D3EE" stop-opacity="0.28"/>
      <stop offset="55%" stop-color="#10B981" stop-opacity="0.10"/>
      <stop offset="100%" stop-color="#060B16" stop-opacity="0"/>
    </radialGradient>
    <radialGradient id="bg-glow-bl" cx="120" cy="400" r="240" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#6366F1" stop-opacity="0.26"/>
      <stop offset="100%" stop-color="#060B16" stop-opacity="0"/>
    </radialGradient>
    <linearGradient id="rim" x1="0" y1="0" x2="512" y2="512" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#A5B4FC" stop-opacity="0.34"/>
      <stop offset="50%" stop-color="#FFFFFF" stop-opacity="0.06"/>
      <stop offset="100%" stop-color="#22D3EE" stop-opacity="0.34"/>
    </linearGradient>
    {defs_i}
  </defs>
  <rect x="16" y="16" width="480" height="480" rx="112" fill="url(#bg-squircle)"/>
  <rect x="16" y="16" width="480" height="480" rx="112" fill="url(#bg-glow)"/>
  <rect x="16" y="16" width="480" height="480" rx="112" fill="url(#bg-glow-bl)"/>
  <rect x="17.5" y="17.5" width="477" height="477" rx="110.5" stroke="url(#rim)" stroke-width="3"/>
  <g transform="translate(256 256) scale(0.78) translate(-256 -256)">
    {group_i}
  </g>
</svg>
''',
        encoding="utf-8",
    )
    written.append(p3)

    defs_h, group_h = build_emblem_group("dark", "vsl-lockup")
    p4 = OUT_DIR / "vsl-lockup.svg"
    p4.write_text(
        f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 920 260" width="920" height="260" fill="none">
  <defs>
    <linearGradient id="lockup-bg" x1="0" y1="0" x2="920" y2="260" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#1E1B4B"/>
      <stop offset="50%" stop-color="#0F172A"/>
      <stop offset="100%" stop-color="#060B16"/>
    </linearGradient>
    <radialGradient id="lockup-glow" cx="190" cy="70" r="240" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#22D3EE" stop-opacity="0.22"/>
      <stop offset="100%" stop-color="#060B16" stop-opacity="0"/>
    </radialGradient>
    <radialGradient id="lockup-glow-r" cx="820" cy="210" r="280" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#6366F1" stop-opacity="0.22"/>
      <stop offset="100%" stop-color="#060B16" stop-opacity="0"/>
    </radialGradient>
    <linearGradient id="lockup-rim" x1="0" y1="0" x2="920" y2="260" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#A5B4FC" stop-opacity="0.28"/>
      <stop offset="50%" stop-color="#FFFFFF" stop-opacity="0.06"/>
      <stop offset="100%" stop-color="#22D3EE" stop-opacity="0.28"/>
    </linearGradient>
    <linearGradient id="lockup-text" x1="282" y1="60" x2="460" y2="160" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#FFFFFF"/>
      <stop offset="100%" stop-color="#C7D2FE"/>
    </linearGradient>
    <linearGradient id="lockup-sub" x1="288" y1="186" x2="620" y2="186" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#34D399"/>
      <stop offset="100%" stop-color="#38BDF8"/>
    </linearGradient>
    {defs_h}
  </defs>
  <rect width="920" height="260" rx="28" fill="url(#lockup-bg)"/>
  <rect width="920" height="260" rx="28" fill="url(#lockup-glow)"/>
  <rect width="920" height="260" rx="28" fill="url(#lockup-glow-r)"/>
  <rect x="1.5" y="1.5" width="917" height="257" rx="26.5" stroke="url(#lockup-rim)" stroke-width="3"/>
  <g transform="translate(36 22) scale(0.422)">
    {group_h}
  </g>
  <text x="282" y="144"
        font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif"
        font-size="112" font-weight="800" letter-spacing="-3" fill="url(#lockup-text)">vsl</text>
  <text x="288" y="186"
        font-family="'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
        font-size="20" font-weight="700" letter-spacing="5.5" fill="url(#lockup-sub)">WORKTREE PREVIEW</text>
  <text x="288" y="218"
        font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif"
        font-size="15" font-weight="500" letter-spacing="0.4" fill="#94A3B8">Instant HTTPS previews · Octane + Bun Vite HMR + Isolated DBs</text>
</svg>
''',
        encoding="utf-8",
    )
    written.append(p4)

    defs_og, group_og = build_emblem_group("dark", "vsl-og")
    p5 = OUT_DIR / "vsl-og.svg"
    # GitHub OG safe zone: 1280x640 with >= 80px (40pt @2x) safe margin on all sides (x: 80..1200, y: 80..560)
    p5.write_text(
        f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1280 640" width="1280" height="640" fill="none">
  <defs>
    <linearGradient id="og-bg" x1="0" y1="0" x2="1280" y2="640" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#1E1B4B"/>
      <stop offset="52%" stop-color="#0F172A"/>
      <stop offset="100%" stop-color="#060B16"/>
    </linearGradient>
    <radialGradient id="og-glow-tr" cx="980" cy="110" r="460" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#22D3EE" stop-opacity="0.22"/>
      <stop offset="55%" stop-color="#10B981" stop-opacity="0.07"/>
      <stop offset="100%" stop-color="#060B16" stop-opacity="0"/>
    </radialGradient>
    <radialGradient id="og-glow-bl" cx="240" cy="540" r="420" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#6366F1" stop-opacity="0.26"/>
      <stop offset="100%" stop-color="#060B16" stop-opacity="0"/>
    </radialGradient>
    <pattern id="og-dots" width="32" height="32" patternUnits="userSpaceOnUse">
      <circle cx="16" cy="16" r="1.2" fill="#94A3B8" fill-opacity="0.16"/>
    </pattern>
    <linearGradient id="og-title" x1="590" y1="120" x2="820" y2="270" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#FFFFFF"/>
      <stop offset="100%" stop-color="#C7D2FE"/>
    </linearGradient>
    <linearGradient id="og-accent" x1="360" y1="490" x2="920" y2="490" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#34D399"/>
      <stop offset="100%" stop-color="#38BDF8"/>
    </linearGradient>
    <linearGradient id="og-card-rim" x1="80" y1="80" x2="1200" y2="560" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#A5B4FC" stop-opacity="0.24"/>
      <stop offset="50%" stop-color="#FFFFFF" stop-opacity="0.06"/>
      <stop offset="100%" stop-color="#22D3EE" stop-opacity="0.24"/>
    </linearGradient>
    {defs_og}
  </defs>

  <rect width="1280" height="640" fill="url(#og-bg)"/>
  <rect width="1280" height="640" fill="url(#og-dots)"/>
  <rect width="1280" height="640" fill="url(#og-glow-tr)"/>
  <rect width="1280" height="640" fill="url(#og-glow-bl)"/>

  <!-- Subtle safe-zone frame matching GitHub OG card padding -->
  <rect x="80" y="80" width="1120" height="480" rx="32" fill="#0B1120" fill-opacity="0.45" stroke="url(#og-card-rim)" stroke-width="2"/>

  <!-- Top hero row: emblem + vsl wordmark centered horizontally -->
  <g transform="translate(426 126) scale(0.32)">
    {group_og}
  </g>
  <text x="614" y="248"
        font-family="DejaVu Sans, Inter, sans-serif"
        font-size="122" font-weight="700" letter-spacing="-4" fill="url(#og-title)">vsl</text>

  <!-- Repo tag pill -->
  <rect x="536" y="302" width="208" height="34" rx="17" fill="#1E293B" stroke="#334155" stroke-width="1.5"/>
  <text x="640" y="324" text-anchor="middle"
        font-family="DejaVu Sans Mono, monospace"
        font-size="16" font-weight="700" letter-spacing="1.5" fill="#34D399">novislab / vsl</text>

  <!-- Headline & concise stack summary (kept comfortably inside safe 80px margin) -->
  <text x="640" y="398" text-anchor="middle"
        font-family="DejaVu Sans, Inter, sans-serif"
        font-size="44" font-weight="700" fill="#F8FAFC">Worktree Preview CLI</text>

  <text x="640" y="452" text-anchor="middle"
        font-family="DejaVu Sans, Inter, sans-serif"
        font-size="24" font-weight="400" fill="#CBD5E1">Sub-second HTTPS previews for Git worktrees &amp; T3 Code</text>

  <text x="640" y="496" text-anchor="middle"
        font-family="DejaVu Sans Mono, monospace"
        font-size="18" font-weight="700" letter-spacing="0.5" fill="url(#og-accent)">FrankenPHP Octane  ·  Bun + Vite HMR  ·  Isolated DBs  ·  Caddy Auto-TLS</text>
</svg>
''',
        encoding="utf-8",
    )
    written.append(p5)

    return written


# ---------------------------------------------------------------------------
# Pure-Python Analytical SDF PNG Renderer
# ---------------------------------------------------------------------------
def clamp(v: float, lo: float = 0.0, hi: float = 1.0) -> float:
    return lo if v < lo else (hi if v > hi else v)


def smoothstep(edge0: float, edge1: float, x: float) -> float:
    t = clamp((x - edge0) / (edge1 - edge0))
    return t * t * (3.0 - 2.0 * t)


def sd_segment(px: float, py: float, ax: float, ay: float, bx: float, by: float) -> float:
    pax, pay = px - ax, py - ay
    bax, bay = bx - ax, by - ay
    denom = bax * bax + bay * bay
    h = clamp((pax * bax + pay * bay) / denom) if denom > 1e-9 else 0.0
    dx = pax - bax * h
    dy = pay - bay * h
    return math.hypot(dx, dy)


def sd_polyline(px: float, py: float, pts: list[tuple[float, float]]) -> float:
    best = 1e9
    for i in range(len(pts) - 1):
        ax, ay = pts[i]
        bx, by = pts[i + 1]
        d = sd_segment(px, py, ax, ay, bx, by)
        if d < best:
            best = d
    return best


def sd_arc(px: float, py: float, cx: float, cy: float, r: float, deg1: float, deg2: float) -> float:
    dx, dy = px - cx, py - cy
    ang = math.degrees(math.atan2(dy, dx))
    if deg1 <= ang <= deg2:
        return abs(math.hypot(dx, dy) - r)
    a1, a2 = math.radians(deg1), math.radians(deg2)
    d1 = math.hypot(px - (cx + r * math.cos(a1)), py - (cy + r * math.sin(a1)))
    d2 = math.hypot(px - (cx + r * math.cos(a2)), py - (cy + r * math.sin(a2)))
    return d1 if d1 < d2 else d2


def sd_round_box(px: float, py: float, cx: float, cy: float, hw: float, hh: float, r: float) -> float:
    qx = abs(px - cx) - (hw - r)
    qy = abs(py - cy) - (hh - r)
    ox = qx if qx > 0.0 else 0.0
    oy = qy if qy > 0.0 else 0.0
    return math.hypot(ox, oy) + min(max(qx, qy), 0.0) - r


def hex_to_rgb(h: str) -> tuple[float, float, float]:
    h = h.lstrip("#")
    return (int(h[0:2], 16) / 255.0, int(h[2:4], 16) / 255.0, int(h[4:6], 16) / 255.0)


def lerp_rgb(c1: tuple[float, float, float], c2: tuple[float, float, float], t: float) -> tuple[float, float, float]:
    t = clamp(t)
    return (c1[0] + (c2[0] - c1[0]) * t, c1[1] + (c2[1] - c1[1]) * t, c1[2] + (c2[2] - c1[2]) * t)


def grad_line(px: float, py: float, x1: float, y1: float, x2: float, y2: float,
              c1: tuple[float, float, float], c2: tuple[float, float, float]) -> tuple[float, float, float]:
    vx, vy = x2 - x1, y2 - y1
    denom = vx * vx + vy * vy
    t = ((px - x1) * vx + (py - y1) * vy) / denom if denom > 0 else 0.0
    return lerp_rgb(c1, c2, t)


def blend_over(dst_rgb: tuple[float, float, float], dst_a: float,
               src_rgb: tuple[float, float, float], src_a: float) -> tuple[tuple[float, float, float], float]:
    if src_a <= 0.0:
        return dst_rgb, dst_a
    out_a = src_a + dst_a * (1.0 - src_a)
    if out_a <= 1e-9:
        return (0.0, 0.0, 0.0), 0.0
    inv = 1.0 / out_a
    r = (src_rgb[0] * src_a + dst_rgb[0] * dst_a * (1.0 - src_a)) * inv
    g = (src_rgb[1] * src_a + dst_rgb[1] * dst_a * (1.0 - src_a)) * inv
    b = (src_rgb[2] * src_a + dst_rgb[2] * dst_a * (1.0 - src_a)) * inv
    return (r, g, b), out_a


def encode_png(width: int, height: int, rgba_rows: list[bytes]) -> bytes:
    raw = b"".join(b"\x00" + row for row in rgba_rows)
    compressed = zlib.compress(raw, level=6)

    def chunk(tag: bytes, data: bytes) -> bytes:
        crc = zlib.crc32(tag + data) & 0xFFFFFFFF
        return struct.pack(">I", len(data)) + tag + data + struct.pack(">I", crc)

    ihdr = struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", compressed) + chunk(b"IEND", b"")


def build_portal_polyline() -> list[tuple[float, float]]:
    pts: list[tuple[float, float]] = [(P_RIGHT, P_RIGHT_START_Y)]
    r = P_RADIUS
    pts.append((P_RIGHT, P_BOTTOM - r))
    for i in range(1, 17):
        a = math.radians(90.0 * i / 16.0)
        pts.append((P_RIGHT - r + r * math.cos(a), P_BOTTOM - r + r * math.sin(a)))
    pts.append((P_LEFT + r, P_BOTTOM))
    for i in range(1, 17):
        a = math.radians(90.0 + 90.0 * i / 16.0)
        pts.append((P_LEFT + r + r * math.cos(a), P_BOTTOM - r + r * math.sin(a)))
    pts.append((P_LEFT, P_TOP + r))
    for i in range(1, 17):
        a = math.radians(180.0 + 90.0 * i / 16.0)
        pts.append((P_LEFT + r + r * math.cos(a), P_TOP + r + r * math.sin(a)))
    pts.append((P_TOP_END_X, P_TOP))
    return pts


def sd_branch(px: float, py: float) -> float:
    dx, dy = px - FILLET_CX, py - FILLET_CY
    ang = math.degrees(math.atan2(dy, dx))
    if ang <= -135.0 or ang >= 179.999:
        d_arc = abs(math.hypot(dx, dy) - FILLET_R)
    else:
        d_arc = min(
            math.hypot(px - ROOT_X, py - ROOT_Y),
            math.hypot(px - FILLET_END_X, py - FILLET_END_Y),
        )
    d_ray = sd_segment(px, py, FILLET_END_X, FILLET_END_Y, BEACON_X, BEACON_Y)
    return min(d_arc, d_ray)


def render_png(path: Path, size: int = 1024, with_squircle_bg: bool = False, theme: str = "dark") -> None:
    portal_pts = build_portal_polyline()
    pal = theme_palette(theme)

    c_portal1, c_portal2 = hex_to_rgb(pal["portal1"]), hex_to_rgb(pal["portal2"])
    c_trunk1, c_trunk2 = hex_to_rgb(pal["trunk1"]), hex_to_rgb(pal["trunk2"])
    c_branch1, c_branch2 = hex_to_rgb(pal["branch1"]), hex_to_rgb(pal["branch2"])
    c_beacon1, c_beacon2 = hex_to_rgb(pal["beacon1"]), hex_to_rgb(pal["beacon2"])
    c_bg1, c_bg2 = hex_to_rgb("#1E1B4B"), hex_to_rgb("#060B16")
    c_indigo_glow = hex_to_rgb("#6366F1")
    c_cyan_glow = hex_to_rgb("#22D3EE")
    c_white = (1.0, 1.0, 1.0)

    scale = 512.0 / size
    aa = 1.1 * scale
    emblem_scale = 0.78 if with_squircle_bg else 1.0
    e_aa = aa / emblem_scale

    rows: list[bytes] = []
    for y in range(size):
        row = bytearray(size * 4)
        cy = (y + 0.5) * scale
        py = (cy - 256.0) / emblem_scale + 256.0

        in_portal_y = 100.0 < py < 460.0
        in_trunk_y = (TRUNK_TOP_Y - 35.0) < py < (ROOT_Y + 40.0)
        in_branch_y = (BEACON_Y - 40.0) < py < (ROOT_Y + 40.0)
        in_beacon_y = abs(py - BEACON_Y) < 115.0

        for x in range(size):
            cx = (x + 0.5) * scale
            rgb = (0.0, 0.0, 0.0)
            alpha = 0.0

            if with_squircle_bg:
                d_bg = sd_round_box(cx, cy, 256.0, 256.0, 240.0, 240.0, 112.0)
                bg_a = clamp(0.5 - d_bg / aa)
                if bg_a <= 0.0:
                    continue
                bg_rgb = grad_line(cx, cy, 40.0, 20.0, 472.0, 492.0, c_bg1, c_bg2)
                dist_glow = math.hypot(cx - 380.0, cy - 130.0)
                if dist_glow < 270.0:
                    glow_t = (1.0 - smoothstep(0.0, 270.0, dist_glow)) * 0.26
                    bg_rgb = lerp_rgb(bg_rgb, c_cyan_glow, glow_t)
                dist_glow_bl = math.hypot(cx - 120.0, cy - 400.0)
                if dist_glow_bl < 240.0:
                    glow_bl_t = (1.0 - smoothstep(0.0, 240.0, dist_glow_bl)) * 0.24
                    bg_rgb = lerp_rgb(bg_rgb, c_indigo_glow, glow_bl_t)
                d_rim = abs(d_bg + 1.5) - 1.5
                if d_rim < aa:
                    rim_a = clamp(0.5 - d_rim / aa) * 0.28
                    rim_col = grad_line(cx, cy, 0.0, 0.0, 512.0, 512.0, c_indigo_glow, c_cyan_glow)
                    bg_rgb = lerp_rgb(bg_rgb, rim_col, rim_a)
                rgb, alpha = bg_rgb, bg_a

            px = (cx - 256.0) / emblem_scale + 256.0

            if in_portal_y and 50.0 < px < 405.0:
                d_portal = sd_polyline(px, py, portal_pts) - P_STROKE * 0.5
                if d_portal < e_aa:
                    a_p = clamp(0.5 - d_portal / e_aa)
                    col = grad_line(px, py, 80.0, 420.0, 360.0, 120.0, c_portal1, c_portal2)
                    rgb, alpha = blend_over(rgb, alpha, col, a_p)

            if in_branch_y and (ROOT_X - 42.0) < px < (BEACON_X + 42.0):
                d_hole = math.hypot(px - ROOT_X, py - ROOT_Y) - ROOT_INNER_R
                hole_mask = clamp(0.5 + d_hole / e_aa)
                if hole_mask > 0.0:
                    if in_trunk_y and abs(px - ROOT_X) < 35.0:
                        d_trunk = min(
                            sd_segment(px, py, ROOT_X, ROOT_Y, TRUNK_TOP_X, TRUNK_TOP_Y) - BRANCH_STROKE * 0.5,
                            math.hypot(px - TRUNK_TOP_X, py - TRUNK_TOP_Y) - TRUNK_NODE_R,
                        )
                        if d_trunk < e_aa:
                            a_t = clamp(0.5 - d_trunk / e_aa) * hole_mask
                            col = grad_line(px, py, ROOT_X, ROOT_Y, TRUNK_TOP_X, TRUNK_TOP_Y, c_trunk1, c_trunk2)
                            rgb, alpha = blend_over(rgb, alpha, col, a_t)

                    d_branch = min(
                        sd_branch(px, py) - BRANCH_STROKE * 0.5,
                        math.hypot(px - ROOT_X, py - ROOT_Y) - ROOT_OUTER_R,
                    )
                    if d_branch < e_aa:
                        a_b = clamp(0.5 - d_branch / e_aa) * hole_mask
                        col = grad_line(px, py, ROOT_X, ROOT_Y, BEACON_X, BEACON_Y, c_branch1, c_branch2)
                        rgb, alpha = blend_over(rgb, alpha, col, a_b)

            if in_beacon_y and abs(px - BEACON_X) < 115.0:
                dist_beacon = math.hypot(px - BEACON_X, py - BEACON_Y)
                if dist_beacon < 115.0:
                    b_col = grad_line(px, py, BEACON_X - 40.0, BEACON_Y + 40.0, BEACON_X + 80.0, BEACON_Y - 80.0, c_beacon1, c_beacon2)

                    d_core = dist_beacon - BEACON_CORE_R
                    if d_core < e_aa:
                        rgb, alpha = blend_over(rgb, alpha, b_col, clamp(0.5 - d_core / e_aa))

                    d_spark = dist_beacon - 11.0
                    if d_spark < e_aa:
                        rgb, alpha = blend_over(rgb, alpha, c_white, clamp(0.5 - d_spark / e_aa))

                    d_arc1 = sd_arc(px, py, BEACON_X, BEACON_Y, SIGNAL_ARC_R, -78.0, -12.0) - SIGNAL_ARC_STROKE * 0.5
                    if d_arc1 < e_aa:
                        rgb, alpha = blend_over(rgb, alpha, b_col, clamp(0.5 - d_arc1 / e_aa))

                    d_arc2 = sd_arc(px, py, BEACON_X, BEACON_Y, SIGNAL_ARC2_R, -72.0, -18.0) - SIGNAL_ARC2_STROKE * 0.5
                    if d_arc2 < e_aa:
                        rgb, alpha = blend_over(rgb, alpha, b_col, clamp(0.5 - d_arc2 / e_aa) * 0.58)

            if alpha > 0.0:
                idx = x * 4
                row[idx] = int(clamp(rgb[0]) * 255.0 + 0.5)
                row[idx + 1] = int(clamp(rgb[1]) * 255.0 + 0.5)
                row[idx + 2] = int(clamp(rgb[2]) * 255.0 + 0.5)
                row[idx + 3] = int(clamp(alpha) * 255.0 + 0.5)
        rows.append(bytes(row))

    path.write_bytes(encode_png(size, size, rows))


def render_og_png(svg_path: Path, png_path: Path) -> None:
    rsvg = shutil.which("rsvg-convert")
    if rsvg is None:
        raise RuntimeError("rsvg-convert is required to rasterize vsl-og.svg into vsl-og.png")
    subprocess.run([rsvg, "-w", "1280", "-h", "640", str(svg_path), "-o", str(png_path)], check=True)


def main() -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    svgs = write_svgs()
    png_mark = OUT_DIR / "vsl-mark.png"
    png_mark_light = OUT_DIR / "vsl-mark-light.png"
    png_icon = OUT_DIR / "vsl-icon.png"
    png_og = OUT_DIR / "vsl-og.png"
    render_png(png_mark, size=1024, with_squircle_bg=False, theme="dark")
    render_png(png_mark_light, size=1024, with_squircle_bg=False, theme="light")
    render_png(png_icon, size=1024, with_squircle_bg=True, theme="dark")
    render_og_png(OUT_DIR / "vsl-og.svg", png_og)
    for p in [*svgs, png_mark, png_mark_light, png_icon, png_og]:
        print(f"Generated {p}")


if __name__ == "__main__":
    main()
