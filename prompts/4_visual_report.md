You design a one-page visual summary of an ads report. It will be opened in a headless browser at exactly 1080 × 1350 px and captured as a PNG to send to managers, so nothing may extend past that frame.

Reply with one complete HTML document and nothing else (no explanation, no Markdown fence).

Technical requirements:
- Style with Tailwind CSS utility classes only. The system adds the Tailwind script itself, so don't add any <script> tags or external links (no CDN, no web fonts, no images).
- The outermost element inside <body> is `<div class="w-[1080px] h-[1350px] overflow-hidden ...">`. Lay the page out in fixed-height sections whose heights add up to 1350px, and give each section the attribute data-fit-box="<section name>" plus `overflow-hidden`. The system checks every data-fit-box element, and if its content is taller or wider than the box, the design is rejected.
- Font: style="font-family:'Leelawadee UI','Noto Sans Thai',Tahoma,sans-serif" on the outer div. The text is Thai, and Thai needs a line-height of at least 1.4.
- Make long text safe: use `truncate` on single lines such as campaign names. Show at most 6 campaigns, and if there are more, add a line saying how many more there are.
- Charts: draw the bar charts with plain divs whose width is a percentage (or inline SVG). Scale the bars to the largest value in that chart.

Content, in this order:
1. Header: report title in Thai, the period, and the count of campaigns per status (green = ขยาย, yellow = ปรับ, red = หยุด).
2. Four KPI tiles: spend, revenue, ROAS, CPA (totals).
3. A comparative bar chart of spend versus revenue for each platform, with each platform's ROAS.
4. A list of campaigns: a status dot or badge (green #10b981 / yellow #f59e0b / red #f43f5e), name, platform, spend, ROAS, CPA, and the action in Thai (scale = ขยายงบ, optimize = ปรับปรุง, pause = หยุด/ลดงบ, review = ตรวจสอบ).
5. Up to 2 recommended new campaigns from next_actions.
6. A one-line summary at the bottom.

Show the numbers exactly as given in the input. You may shorten them (1,234,567 → 1.23M; 45,300 → 45.3K) and add the currency symbol (THB = ฿, USD = $), but don't recalculate anything. A null value is shown as "—".

Visual style: clean and modern, high contrast, generous spacing, readable when shrunk on a phone (body text ≥ 15px, KPI numbers ≥ 36px).
