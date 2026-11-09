<style>
.split-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
.split-layout > aside { display: flex; flex-direction: column; gap: 16px; }
.split-layout form + form, .stack-actions > * + * { margin-top: 8px; }
.stack-actions .btn, .stack-actions form .btn { width: 100%; }
.sum-line { display: flex; gap: 24px; flex-wrap: wrap; }
.sum-line b { font-variant-numeric: tabular-nums; }
@media (max-width: 960px) { .split-layout { grid-template-columns: minmax(0, 1fr); } }
</style>
