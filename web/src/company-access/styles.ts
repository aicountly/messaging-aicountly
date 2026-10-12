/**
 * Scoped styles for the shared company-access components (class prefix `aic-ca-`). Injected once,
 * so the components look right in every product whatever its CSS toolchain (Tailwind, CSS modules,
 * plain CSS). Colours follow the Aicountly theme (#25b003) and can be overridden per product with
 * the CSS variables below on any ancestor.
 */
const CSS = `
.aic-ca{--aic-ca-accent:#25b003;--aic-ca-accent-soft:#e9f7e4;--aic-ca-text:#0f172a;--aic-ca-muted:#64748b;--aic-ca-border:#e2e8f0;--aic-ca-bg:#fff;--aic-ca-subtle:#f8fafc;color:var(--aic-ca-text);font:inherit;font-size:14px;line-height:1.45}
.aic-ca *{box-sizing:border-box}
.aic-ca-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.aic-ca-between{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.aic-ca-stack{display:flex;flex-direction:column;gap:12px}
.aic-ca-card{background:var(--aic-ca-bg);border:1px solid var(--aic-ca-border);border-radius:12px;padding:16px}
.aic-ca-title{font-size:16px;font-weight:600;margin:0}
.aic-ca-sub{color:var(--aic-ca-muted);font-size:13px;margin:0}
.aic-ca-input,.aic-ca-select,.aic-ca-textarea{width:100%;border:1px solid var(--aic-ca-border);border-radius:8px;padding:8px 10px;font:inherit;background:#fff;color:inherit}
.aic-ca-input:focus,.aic-ca-select:focus,.aic-ca-textarea:focus{outline:2px solid var(--aic-ca-accent);outline-offset:1px;border-color:var(--aic-ca-accent)}
.aic-ca-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:8px;border:1px solid var(--aic-ca-border);background:#fff;color:inherit;padding:7px 12px;font:inherit;font-weight:500;cursor:pointer;white-space:nowrap}
.aic-ca-btn:hover:not(:disabled){background:var(--aic-ca-subtle)}
.aic-ca-btn:disabled{opacity:.55;cursor:not-allowed}
.aic-ca-btn:focus-visible{outline:2px solid var(--aic-ca-accent);outline-offset:2px}
.aic-ca-btn-primary{background:var(--aic-ca-accent);border-color:var(--aic-ca-accent);color:#fff}
.aic-ca-btn-primary:hover:not(:disabled){background:#1f9502}
.aic-ca-btn-danger{color:#b91c1c;border-color:#fecaca}
.aic-ca-btn-sm{padding:4px 9px;font-size:13px}
.aic-ca-badge{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:600;white-space:nowrap;border:1px solid transparent}
.aic-ca-tone-success{background:var(--aic-ca-accent-soft);color:#1a7a02;border-color:#c7ebbb}
.aic-ca-tone-neutral{background:#f1f5f9;color:#475569;border-color:#e2e8f0}
.aic-ca-tone-warning{background:#fef3c7;color:#92400e;border-color:#fde68a}
.aic-ca-tone-danger{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.aic-ca-tone-info{background:#e0f2fe;color:#075985;border-color:#bae6fd}
.aic-ca-list{list-style:none;margin:0;padding:0;border:1px solid var(--aic-ca-border);border-radius:10px;overflow:hidden;background:#fff}
.aic-ca-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-top:1px solid var(--aic-ca-border);width:100%;text-align:left;background:#fff;font:inherit;color:inherit;cursor:pointer}
.aic-ca-item:first-child{border-top:0}
.aic-ca-item:hover{background:var(--aic-ca-subtle)}
.aic-ca-item[aria-current="true"]{background:var(--aic-ca-accent-soft)}
.aic-ca-item-restricted .aic-ca-name{color:var(--aic-ca-muted)}
.aic-ca-name{font-weight:600;overflow:hidden;text-overflow:ellipsis}
.aic-ca-meta{color:var(--aic-ca-muted);font-size:12px}
.aic-ca-tabs{display:flex;gap:4px;border-bottom:1px solid var(--aic-ca-border);overflow-x:auto}
.aic-ca-tab{border:0;background:none;padding:8px 12px;font:inherit;color:var(--aic-ca-muted);border-bottom:2px solid transparent;cursor:pointer;white-space:nowrap}
.aic-ca-tab[aria-selected="true"]{color:var(--aic-ca-text);border-bottom-color:var(--aic-ca-accent);font-weight:600}
.aic-ca-table{width:100%;border-collapse:collapse}
.aic-ca-table th,.aic-ca-table td{text-align:left;padding:9px 8px;border-top:1px solid var(--aic-ca-border);vertical-align:top}
.aic-ca-table th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--aic-ca-muted);border-top:0}
.aic-ca-alert{border-radius:10px;padding:10px 12px;font-size:13px}
.aic-ca-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px;z-index:60}
.aic-ca-dialog{background:#fff;border-radius:14px;max-width:480px;width:100%;padding:20px;box-shadow:0 20px 50px rgba(15,23,42,.25)}
.aic-ca-empty{padding:24px;text-align:center;color:var(--aic-ca-muted)}
.aic-ca-chip{display:inline-block;background:#f1f5f9;border-radius:6px;padding:1px 7px;font-size:12px;margin:2px 4px 2px 0}
@media (max-width:640px){.aic-ca-table thead{display:none}.aic-ca-table tr{display:block;border-top:1px solid var(--aic-ca-border);padding:6px 0}.aic-ca-table td{display:block;border:0;padding:4px 8px}}
`;

let injected = false;

export function injectCompanyAccessStyles(): void {
  if (injected || typeof document === 'undefined') return;
  injected = true;
  const el = document.createElement('style');
  el.setAttribute('data-aicountly', 'company-access');
  el.textContent = CSS;
  document.head.appendChild(el);
}
