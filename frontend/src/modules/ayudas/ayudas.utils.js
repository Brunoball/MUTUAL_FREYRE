export const localDateValue = (date = new Date()) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

export const addMonths = (dateValue, months = 1) => {
  const [year, month, day] = String(dateValue || localDateValue())
    .split("-")
    .map(Number);
  const source = new Date(year, month - 1 + months, 1);
  const lastDay = new Date(source.getFullYear(), source.getMonth() + 1, 0).getDate();
  source.setDate(Math.min(day || 1, lastDay));
  return localDateValue(source);
};

export const formatDate = (value) => {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ""));
  return match ? `${match[3]}/${match[2]}/${match[1]}` : "—";
};

export const numericValue = (value) => {
  if (typeof value === "number") return Number.isFinite(value) ? value : 0;
  const source = String(value ?? "").trim();
  if (!source) return 0;
  const normalized = source.includes(",")
    ? source.replace(/\./g, "").replace(",", ".")
    : source;
  const parsed = Number(normalized.replace(/[^0-9.-]/g, ""));
  return Number.isFinite(parsed) ? parsed : 0;
};

export const formatMoney = (value, currency = "ARS") =>
  new Intl.NumberFormat("es-AR", {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(numericValue(value));

export const formatRate = (value) =>
  `${new Intl.NumberFormat("es-AR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  }).format(numericValue(value))}%`;

export const aidNumber = (value) => String(value || 0).padStart(8, "0");

export const statusTone = (status, overdue = false) => {
  if (overdue && status === "VIGENTE") return "is-warning";
  if (status === "VIGENTE") return "is-success";
  if (status === "ANULADA") return "is-danger";
  return "is-neutral";
};

export const statusLabel = (status, overdue = false) => {
  if (overdue && status === "VIGENTE") return "VENCIDA";
  return String(status || "—").replaceAll("_", " ");
};

const escapeHtml = (value) =>
  String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

const row = (label, value) =>
  `<div class="datum"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`;

export const printMutuo = (detail) => {
  const aid = detail?.ayuda || {};
  const mutuo = detail?.mutuo || {};
  const snapshot = mutuo?.datos_snapshot || {};
  const currency = aid.moneda || "ARS";
  const guarantors = detail?.garantes || [];
  const installments = detail?.cuotas || [];
  const checks = detail?.cheques || [];
  const popup = window.open("", "_blank", "width=980,height=760");
  if (!popup) throw new Error("El navegador bloqueó la ventana de impresión.");
  popup.opener = null;

  const planRows = installments
    .map(
      (item) => `<tr>
        <td>${item.numero_cuota}</td>
        <td>${formatDate(item.fecha_vencimiento)}</td>
        <td>${formatMoney(item.amortizacion_capital, currency)}</td>
        <td>${formatMoney(item.devengamiento, currency)}</td>
        <td>${formatMoney(item.importe_cuota, currency)}</td>
      </tr>`,
    )
    .join("");

  const checkRows = checks
    .map(
      (item) => `<tr>
        <td>${escapeHtml(item.banco)}</td>
        <td>${escapeHtml(item.numero_cheque)}</td>
        <td>${formatDate(item.fecha_acreditacion)}</td>
        <td>${formatMoney(item.importe, "ARS")}</td>
      </tr>`,
    )
    .join("");

  popup.document.write(`<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Mutuo ${aidNumber(mutuo.numero_mutuo)}</title>
<style>
  @page { size: A4; margin: 18mm; }
  * { box-sizing: border-box; }
  body { margin: 0; color: #111827; font: 12px/1.45 Arial, sans-serif; }
  h1 { margin: 0; font-size: 22px; text-align: center; }
  h2 { margin: 18px 0 8px; font-size: 14px; border-bottom: 1px solid #cbd5e1; padding-bottom: 5px; }
  .header { text-align: center; margin-bottom: 18px; }
  .header p { margin: 4px 0 0; color: #475569; }
  .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px 18px; }
  .datum { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px dotted #cbd5e1; padding: 4px 0; }
  .datum span { color: #64748b; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 11px; }
  th, td { border: 1px solid #cbd5e1; padding: 5px; text-align: right; }
  th:first-child, td:first-child, th:nth-child(2), td:nth-child(2) { text-align: left; }
  .legal { margin-top: 18px; padding: 10px; border: 1px solid #f59e0b; background: #fffbeb; color: #78350f; }
  .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; margin-top: 70px; text-align: center; }
  .signature { border-top: 1px solid #111827; padding-top: 7px; }
  .small { color: #64748b; font-size: 10px; }
  @media print { .no-print { display: none; } }
</style></head><body>
  <div class="header">
    <h1>MUTUO DE AYUDA ECONÓMICA</h1>
    <p>Asociación Mutual 9 de Julio Olímpico - Freyre</p>
    <p>Mutuo N° ${aidNumber(mutuo.numero_mutuo)} · Ayuda N° ${aidNumber(aid.numero_ayuda)}</p>
  </div>
  <div class="grid">
    ${row("Socio", aid.socio_nombre || snapshot?.socio?.nombre || "—")}
    ${row("N° de socio", aid.numero_socio || snapshot?.socio?.numero_socio || "—")}
    ${row("Documento", aid.documento || snapshot?.socio?.documento || "—")}
    ${row("Tipo", `${aid.tipo || "—"} - ${aid.producto_nombre || ""}`)}
    ${row("Fecha de liquidación", formatDate(aid.fecha_liquidacion))}
    ${row("Vencimiento final", formatDate(aid.fecha_vencimiento))}
    ${row("Capital", formatMoney(aid.capital_original, currency))}
    ${row("TNA", formatRate(aid.tna))}
    ${row("Rubro", aid.rubro || "—")}
    ${row("Destino", aid.destino || "—")}
    ${row("Garantía", String(aid.tipo_garantia || "SIN GARANTÍA").replaceAll("_", " "))}
    ${row("Total", formatMoney(aid.total_a_devolver, currency))}
  </div>
  <h2>Garantes</h2>
  <p>${guarantors.length ? guarantors.map((item) => `${escapeHtml(item.nombre)} (${escapeHtml(item.documento || "S/D")})`).join(" · ") : "Sin garantes registrados."}</p>
  ${installments.length ? `<h2>Plan de vencimientos</h2><table><thead><tr><th>Cuota</th><th>Vencimiento</th><th>Capital</th><th>Interés</th><th>Total</th></tr></thead><tbody>${planRows}</tbody></table>` : ""}
  ${checks.length ? `<h2>Valores entregados</h2><table><thead><tr><th>Banco</th><th>N° cheque</th><th>Acreditación</th><th>Importe</th></tr></thead><tbody>${checkRows}</tbody></table>` : ""}
  <div class="legal">${escapeHtml(snapshot.leyenda || "El texto contractual definitivo debe reemplazarse por la plantilla legal aprobada por la Mutual y su asesoría jurídica.")}</div>
  <div class="signatures">
    <div class="signature">Firma del socio</div>
    <div class="signature">Firma de garante/s</div>
    <div class="signature">Firma Mutual</div>
  </div>
  <p class="small">Documento generado desde el módulo de Ayudas Económicas. Conserva el detalle histórico de la liquidación.</p>
  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));<\/script>
</body></html>`);
  popup.document.close();
};
