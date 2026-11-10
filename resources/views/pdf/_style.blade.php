<style>
    @page { margin: 48px 52px 120px 52px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #0a0a0a; font-size: 9.5px; line-height: 1.5; }
    table { border-collapse: collapse; }
    td, th { vertical-align: top; }
    .muted { color: #6b6b6b; }
    .small { font-size: 9px; }
    .right { text-align: right; }
    .pre { white-space: pre-wrap; }
    .nowrap { white-space: nowrap; }

    /* header: big title on the left, logo (or company name) on the right */
    .head { width: 100%; margin-bottom: 44px; }
    .title { font-size: 30px; line-height: 1.1; letter-spacing: -0.5px; margin: 0; text-transform: uppercase; }
    .stamp { display: inline-block; margin-top: 10px; padding: 2px 9px; border: 1px solid #0a0a0a; font-size: 8px; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; }
    .stamp.good { border-color: #1a7f37; color: #1a7f37; }
    .stamp.bad { border-color: #b42318; color: #b42318; }
    .logo { max-height: 56px; max-width: 190px; }
    .co-name { font-size: 15px; font-weight: bold; }

    /* who it is for, and the document facts */
    .info { width: 100%; margin-bottom: 44px; }
    .info td { padding: 0; }
    .info b { font-size: 10px; }
    .info .row { margin-top: 1px; }

    /* line items: header row, hairline between rows, nothing else */
    .items { width: 100%; margin-bottom: 18px; }
    .items th { text-align: left; font-size: 9px; font-weight: bold; padding: 0 8px 9px 0; border-bottom: 1px solid #0a0a0a; }
    .items td { padding: 9px 8px 9px 0; border-bottom: 1px solid #ececec; }
    .items th.right, .items td.right { text-align: right; padding-right: 0; }
    .items .qty { text-align: right; padding-right: 8px; }

    .totals { width: 46%; margin-left: 54%; margin-bottom: 30px; }
    .totals td { padding: 3px 0; }
    .totals td.right { text-align: right; }
    .totals tr.total td { font-weight: bold; padding-top: 8px; border-top: 1px solid #0a0a0a; }
    .totals tr.due td { font-weight: bold; font-size: 11px; color: {{ $accent }}; padding-top: 4px; }

    .box { margin-bottom: 14px; }
    .box b { font-size: 9.5px; }
    .paylink { color: {{ $accent }}; }

    /* footer pinned to the bottom of every page */
    .foot { position: fixed; bottom: -84px; left: 0; right: 0; }
    .foot table { width: 100%; }
    .foot td { font-size: 9px; line-height: 1.5; }
    .foot .name { font-weight: bold; font-size: 10px; }
    .foot .thanks { font-size: 12px; text-align: right; vertical-align: bottom; }
</style>
