<style>
    @page { margin: 42px 44px 56px 44px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #0a0a0a; font-size: 10px; line-height: 1.45; }
    table { border-collapse: collapse; }
    .muted { color: #666666; }
    .small { font-size: 9px; }
    .right { text-align: right; }
    .pre { white-space: pre-wrap; }
    .head { width: 100%; border-bottom: 2px solid {{ $accent }}; margin-bottom: 22px; }
    .head td { vertical-align: top; padding: 0 0 14px 0; }
    .logo { max-height: 48px; max-width: 200px; margin-bottom: 6px; }
    .co-name { font-size: 14px; font-weight: bold; }
    .title { font-size: 22px; font-weight: bold; margin: 0 0 4px 0; color: #0a0a0a; }
    .stamp { display: inline-block; padding: 2px 10px; border: 2px solid #0a0a0a; font-size: 11px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin: 4px 0 6px 0; }
    .stamp.good { border-color: #1a7f37; color: #1a7f37; }
    .stamp.bad { border-color: #b42318; color: #b42318; }
    .meta td { padding: 1px 0 1px 12px; font-size: 10px; }
    .meta td.k { color: #666666; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #666666; margin-bottom: 3px; }
    .bill { margin-bottom: 20px; }
    .items { width: 100%; margin-bottom: 14px; }
    .items th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #666666; border-bottom: 1px solid #0a0a0a; padding: 6px 6px 6px 0; }
    .items th.right, .items td.right { text-align: right; padding-right: 0; padding-left: 6px; }
    .items td { border-bottom: 1px solid #e5e5e5; padding: 7px 6px 7px 0; vertical-align: top; }
    .totals { width: 250px; margin-left: 330px; margin-bottom: 20px; }
    .totals td { padding: 3px 0; }
    .totals tr.total td { border-top: 2px solid #0a0a0a; font-weight: bold; font-size: 12px; padding-top: 6px; }
    .totals tr.due td { color: {{ $accent }}; font-weight: bold; font-size: 12px; }
    .box { margin-bottom: 12px; }
    .box b { font-size: 10px; }
    .paylink { color: {{ $accent }}; }
    .foot { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 8px; color: #666666; border-top: 1px solid #e5e5e5; padding-top: 5px; }
</style>
