import React from "react";
import CrudModal from "../../Global/components/CrudModal";
import GlobalIcon from "../../Global/components/GlobalIcon";
import {
  aidNumber,
  formatDate,
  formatMoney,
  formatRate,
  printMutuo,
  localDateValue,
  statusLabel,
  statusTone,
} from "./ayudas.utils";

const text = (value, fallback = "—") =>
  value === undefined || value === null || String(value).trim() === ""
    ? fallback
    : String(value).replaceAll("_", " ");

function Datum({ label, children, strong = false }) {
  return (
    <div className="ayuda-detail-datum">
      <span>{label}</span>
      <strong className={strong ? "is-emphasis" : ""}>{children}</strong>
    </div>
  );
}

export default function AyudaDetalleModal({
  open,
  detail,
  canManage,
  onClose,
  onRenew,
  onAnnul,
}) {
  const aid = detail?.ayuda || {};
  const installments = Array.isArray(detail?.cuotas) ? detail.cuotas : [];
  const checks = Array.isArray(detail?.cheques) ? detail.cheques : [];
  const guarantors = Array.isArray(detail?.garantes) ? detail.garantes : [];
  const movements = Array.isArray(detail?.movimiento_caja)
    ? detail.movimiento_caja
    : [];
  const currency = aid.moneda || "ARS";
  const overdue = Boolean(Number(aid.vencida || 0)) ||
    (aid.estado === "VIGENTE" && aid.fecha_vencimiento && aid.fecha_vencimiento < localDateValue());
  const renewable = canManage && aid.tipo === "B" && aid.estado === "VIGENTE" && aid.fecha_vencimiento <= localDateValue();
  const annullable = canManage && aid.estado === "VIGENTE";

  const handlePrint = () => {
    try {
      printMutuo(detail);
    } catch (error) {
      window.alert(error?.message || "No se pudo abrir el mutuo.");
    }
  };

  return (
    <CrudModal
      footerStart={
        <div className="ayuda-detail-actions">
          <button className="global-button global-button--ghost" onClick={handlePrint} type="button">
            <GlobalIcon name="info" size={16} /> Imprimir mutuo
          </button>
          {renewable ? (
            <button className="global-button global-button--ghost" onClick={() => onRenew?.(detail)} type="button">
              <GlobalIcon name="refresh" size={16} /> Renovar ayuda B
            </button>
          ) : null}
          {annullable ? (
            <button className="global-button global-button--danger" onClick={() => onAnnul?.(detail)} type="button">
              <GlobalIcon name="trash" size={16} /> Anular liquidación
            </button>
          ) : null}
        </div>
      }
      hideSubmit
      modalClassName="ayuda-detail-modal"
      onClose={onClose}
      open={open}
      subtitle={`${aid.producto_nombre || "Ayuda económica"} · Socio N° ${aid.numero_socio || "—"}`}
      title={`Ayuda N° ${aidNumber(aid.numero_ayuda)}`}
      wide
    >
      <div className="ayuda-detail-heading">
        <div>
          <span className="ayuda-type-badge">{aid.tipo || "—"}</span>
          <div>
            <strong>{aid.socio_nombre || "SOCIO SIN INFORMAR"}</strong>
            <small>{aid.documento || "DOCUMENTO SIN INFORMAR"}</small>
          </div>
        </div>
        <span className={`global-chip ${statusTone(aid.estado, overdue)}`}>
          {statusLabel(aid.estado, overdue)}
        </span>
      </div>

      {aid.tipo_operacion === "RENOVACION" ? (
        <div className="ayuda-alert is-info">
          <GlobalIcon name="refresh" size={18} />
          <span>
            Esta liquidación es una renovación de la ayuda anterior. No generó un segundo desembolso de capital.
          </span>
        </div>
      ) : null}

      <section className="ayuda-detail-section">
        <header><strong>Condiciones de la liquidación</strong></header>
        <div className="ayuda-detail-grid">
          <Datum label="Solicitud">N° {aidNumber(aid.numero_solicitud)}</Datum>
          <Datum label="Fecha solicitud">{formatDate(aid.fecha_solicitud)}</Datum>
          <Datum label="Fecha liquidación">{formatDate(aid.fecha_liquidacion)}</Datum>
          <Datum label="Vencimiento final">{formatDate(aid.fecha_vencimiento)}</Datum>
          <Datum label="Sistema">{text(aid.sistema_amortizacion)}</Datum>
          <Datum label="Periodicidad">{text(aid.periodicidad)}</Datum>
          <Datum label="Plazo">{aid.plazo_cantidad || "—"} {text(aid.plazo_unidad, "")}</Datum>
          <Datum label="Garantía">{text(aid.tipo_garantia)}</Datum>
          <Datum label="Base de cálculo">{aid.base_dias || 365} días</Datum>
          <Datum label="Rubro">{text(aid.rubro)}</Datum>
          <Datum label="Destino">{text(aid.destino)}</Datum>
          <Datum label="Detalle">{text(aid.detalle)}</Datum>
          <Datum label="Operador">{text(aid.creado_por_nombre)}</Datum>
        </div>
        {aid.observaciones ? <p className="ayuda-detail-note"><b>Observaciones:</b> {aid.observaciones}</p> : null}
      </section>

      <section className="ayuda-detail-section">
        <header><strong>Importes y tasas</strong></header>
        <div className="ayuda-summary-grid ayuda-summary-grid--detail">
          <div><span>Capital</span><strong>{formatMoney(aid.capital_original, currency)}</strong></div>
          {aid.tipo === "I" ? (
            <>
              <div><span>Cotización aplicada</span><strong>{formatMoney(aid.cotizacion_dolar, "ARS")}</strong></div>
              <div><span>Equivalente liquidado</span><strong>{formatMoney(aid.capital_equivalente_ars, "ARS")}</strong></div>
            </>
          ) : null}
          <div><span>Devengamiento</span><strong>{formatMoney(aid.devengamiento_total, currency)}</strong></div>
          <div><span>Gastos administrativos</span><strong>{formatMoney(aid.gastos_administrativos, currency)}</strong></div>
          <div><span>Otros / recupero</span><strong>{formatMoney(Number(aid.otros_gastos || 0) + Number(aid.recupero_gastos || 0), currency)}</strong></div>
          <div><span>Sellado / seguro</span><strong>{formatMoney(Number(aid.sellado || 0) + Number(aid.seguro || 0), currency)}</strong></div>
          <div><span>Total a devolver</span><strong>{formatMoney(aid.total_a_devolver, currency)}</strong></div>
          <div><span>Acreditado en caja común</span><strong>{formatMoney(aid.importe_acreditado_ars, "ARS")}</strong></div>
          <div><span>TEM / TNA</span><strong>{formatRate(aid.tem)} / {formatRate(aid.tna)}</strong></div>
          <div><span>TEA / CFT</span><strong>{formatRate(aid.tea)} / {formatRate(aid.cft)}</strong></div>
        </div>
      </section>

      <section className="ayuda-detail-section">
        <header><strong>Garantes</strong></header>
        {guarantors.length ? (
          <div className="ayuda-guarantor-list">
            {guarantors.map((item) => (
              <div key={item.id_garante || item.id_persona}>
                <span>Garante {item.orden}</span>
                <strong>{item.nombre}</strong>
                <small>{item.documento || "SIN DOCUMENTO"}</small>
              </div>
            ))}
          </div>
        ) : <p className="ayuda-empty-inline">No se registraron garantes.</p>}
      </section>

      {checks.length ? (
        <section className="ayuda-detail-section">
          <header><strong>Valores / cheques</strong></header>
          <div className="ayuda-table-scroll">
            <table className="ayuda-data-table">
              <thead>
                <tr><th>Banco</th><th>N° cheque</th><th>Cuenta</th><th>Emisión</th><th>Acreditación</th><th>Importe</th><th>Devengamiento</th><th>Clase</th></tr>
              </thead>
              <tbody>
                {checks.map((item) => (
                  <tr key={item.id_cheque}>
                    <td>{item.banco}</td>
                    <td>{item.numero_cheque}</td>
                    <td>{item.cuenta || "—"}</td>
                    <td>{formatDate(item.fecha_emision)}</td>
                    <td>{formatDate(item.fecha_acreditacion)}</td>
                    <td>{formatMoney(item.importe, "ARS")}</td>
                    <td>{formatMoney(item.devengamiento, "ARS")}</td>
                    <td>{Number(item.electronico) ? "ECHEQ" : "FÍSICO"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      {installments.length ? (
        <section className="ayuda-detail-section">
          <header>
            <strong>Plan de cuotas / vencimientos</strong>
            <span>{installments.length} registro{installments.length === 1 ? "" : "s"}</span>
          </header>
          <div className="ayuda-table-scroll ayuda-installment-scroll">
            <table className="ayuda-data-table">
              <thead>
                <tr><th>N°</th><th>Vencimiento</th><th>Saldo inicial</th><th>Amortización</th><th>Devengamiento</th><th>Gastos</th><th>Seguro</th><th>Cuota</th><th>Estado</th></tr>
              </thead>
              <tbody>
                {installments.map((item) => {
                  const costs = Number(item.gastos_administrativos || 0) +
                    Number(item.otros_gastos || 0) +
                    Number(item.recupero_gastos || 0) +
                    Number(item.sellado || 0);
                  return (
                    <tr key={item.id_cuota || item.numero_cuota}>
                      <td>{item.numero_cuota}</td>
                      <td>{formatDate(item.fecha_vencimiento)}</td>
                      <td>{formatMoney(item.saldo_inicial, currency)}</td>
                      <td>{formatMoney(item.amortizacion_capital, currency)}</td>
                      <td>{formatMoney(item.devengamiento, currency)}</td>
                      <td>{formatMoney(costs, currency)}</td>
                      <td>{formatMoney(item.seguro, currency)}</td>
                      <td><b>{formatMoney(item.importe_cuota, currency)}</b></td>
                      <td><span className={`global-chip ${item.estado === "PAGADA" ? "is-success" : item.estado === "ANULADA" ? "is-danger" : "is-warning"}`}>{item.estado}</span></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      <section className="ayuda-detail-section">
        <header><strong>Caja de ahorro común</strong></header>
        <div className="ayuda-detail-grid">
          <Datum label="Saldo actual" strong>{formatMoney(aid.saldo_caja_ahorro_comun, "ARS")}</Datum>
          <Datum label="Medio de desembolso">{text(aid.medio_desembolso)}</Datum>
          <Datum label="Mutuo">N° {aidNumber(detail?.mutuo?.numero_mutuo)}</Datum>
          <Datum label="Estado del mutuo">{text(detail?.mutuo?.estado)}</Datum>
        </div>
        {movements.length ? (
          <div className="ayuda-movement-list">
            {movements.map((item) => (
              <div key={item.id_movimiento}>
                <span>{formatDate(item.fecha_movimiento)}</span>
                <strong>{text(item.tipo_movimiento)}</strong>
                <span>{item.concepto}</span>
                <b>{formatMoney(item.importe, "ARS")}</b>
                <small>Saldo: {formatMoney(item.saldo_posterior, "ARS")}</small>
              </div>
            ))}
          </div>
        ) : <p className="ayuda-empty-inline">Esta ayuda no registró movimiento de desembolso, por ejemplo por tratarse de una renovación.</p>}
      </section>

      {detail?.renovacion ? (
        <div className="ayuda-alert is-info">
          <GlobalIcon name="refresh" size={18} />
          <span>
            Renovada el {formatDate(detail.renovacion.fecha_renovacion)} como ayuda N° {aidNumber(detail.renovacion.numero_ayuda_nueva)}. Intereses cobrados: {formatMoney(detail.renovacion.intereses_cobrados, "ARS")}.
          </span>
        </div>
      ) : null}

      {aid.motivo_anulacion ? (
        <div className="ayuda-alert is-error">
          <GlobalIcon name="error" size={18} />
          <span><b>Motivo de anulación:</b> {aid.motivo_anulacion}</span>
        </div>
      ) : null}
    </CrudModal>
  );
}
