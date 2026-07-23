import React, { useEffect, useMemo, useState } from "react";
import CrudModal from "../../Global/components/CrudModal";
import GlobalIcon from "../../Global/components/GlobalIcon";
import SearchableSelect from "../../Global/components/SearchableSelect";
import { getAyudasCatalogos, liquidarAyuda, simularAyuda } from "./ayudas.api";
import {
  addMonths,
  formatDate,
  formatMoney,
  formatRate,
  localDateValue,
  numericValue,
} from "./ayudas.utils";

const emptyCheck = (date) => ({
  banco: "",
  sucursal: "",
  localidad: "",
  codigo_postal: "",
  numero_cheque: "",
  cuenta: "",
  cuit_librador: "",
  fecha_emision: date,
  fecha_acreditacion: date,
  importe: "",
  endosado: true,
  electronico: false,
  observaciones: "",
});

const initialForm = () => {
  const today = localDateValue();
  return {
    tipo: "E",
    id_asociado: "",
    fecha_solicitud: today,
    fecha_liquidacion: today,
    capital: "",
    plazo_dias: "30",
    cantidad_cuotas: "12",
    periodicidad: "MENSUAL",
    fecha_primer_vencimiento: addMonths(today, 1),
    rubro: "OTRAS NECESIDADES",
    destino: "",
    detalle: "",
    observaciones: "",
    tipo_garantia: "SIN GARANTIA",
    garante_1: "",
    garante_2: "",
    gastos_administrativos: "0",
    otros_gastos: "0",
    recupero_gastos: "0",
    sellado: "0",
    seguro: "0",
    cheques: [emptyCheck(today)],
  };
};

const productGroup = (catalogs, type) =>
  catalogs?.productos?.find((item) => item.codigo === type)?.grupo_tasa || "";

const parseErrors = (error) => ({
  message: error?.message || "No se pudo procesar la liquidación.",
  fields: error?.fields || {},
});

function Field({ label, required, error, children, className = "" }) {
  return (
    <label className={`ayuda-field ${error ? "has-error" : ""} ${className}`.trim()}>
      <span>
        {label} {required ? <b>*</b> : null}
      </span>
      {children}
      {error ? <small>{error}</small> : null}
    </label>
  );
}

export default function AyudaModal({ open, catalogs, onClose, onLiquidated }) {
  const [form, setForm] = useState(() => initialForm());
  const [localCatalogs, setLocalCatalogs] = useState(catalogs || {});
  const [catalogLoading, setCatalogLoading] = useState(false);
  const [simulation, setSimulation] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!open) return;
    setForm(initialForm());
    setLocalCatalogs(catalogs || {});
    setSimulation(null);
    setError(null);
  }, [open, catalogs]);

  useEffect(() => {
    if (!open || !form.fecha_liquidacion) return undefined;
    let active = true;
    const timer = setTimeout(async () => {
      setCatalogLoading(true);
      try {
        const response = await getAyudasCatalogos(form.fecha_liquidacion);
        if (active) setLocalCatalogs(response || {});
      } catch (loadError) {
        if (active) setError(parseErrors(loadError));
      } finally {
        if (active) setCatalogLoading(false);
      }
    }, 180);
    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [form.fecha_liquidacion, open]);

  const group = productGroup(localCatalogs, form.tipo);
  const activeRate = localCatalogs?.tasas?.[group] || null;
  const quote = localCatalogs?.cotizacion_mes || null;
  const currency = form.tipo === "I" ? "USD" : "ARS";
  const selectedAssociate = (localCatalogs?.socios || []).find(
    (item) => Number(item.id) === Number(form.id_asociado),
  );
  const guarantorOptions = (localCatalogs?.garantes || []).filter(
    (item) => Number(item.id) !== Number(selectedAssociate?.id_persona || 0),
  );
  const associateOptions = (localCatalogs?.socios || []).map((item) => ({
    value: String(item.id),
    label: `N° ${item.numero_socio} · ${item.nombre} · ${item.documento || "S/D"}`,
    searchText: `${item.numero_socio || ""} ${item.nombre || ""} ${item.documento || ""}`,
  }));
  const toGuarantorOption = (item) => ({
    value: String(item.id),
    label: `${item.nombre} · ${item.documento || "S/D"}`,
    searchText: `${item.nombre || ""} ${item.documento || ""}`,
  });

  const update = (field, value) => {
    setForm((current) => {
      const next = { ...current, [field]: value };
      if (field === "id_asociado") {
        const associate = (localCatalogs?.socios || []).find(
          (item) => Number(item.id) === Number(value),
        );
        const personId = Number(associate?.id_persona || 0);
        if (Number(next.garante_1) === personId) next.garante_1 = "";
        if (Number(next.garante_2) === personId) next.garante_2 = "";
      }
      if (field === "tipo") {
        if (value === "E") next.cantidad_cuotas = "12";
        if (value === "B") next.plazo_dias = "30";
        if (value === "J") next.periodicidad = "MENSUAL";
        next.fecha_primer_vencimiento = addMonths(
          next.fecha_liquidacion,
          value === "J" && next.periodicidad === "SEMESTRAL" ? 6 : 1,
        );
      }
      if (field === "fecha_liquidacion") {
        next.fecha_solicitud = current.fecha_solicitud || value;
        next.fecha_primer_vencimiento = addMonths(
          value,
          current.tipo === "J" && current.periodicidad === "SEMESTRAL" ? 6 : 1,
        );
        next.cheques = current.cheques.map((item) => ({
          ...item,
          fecha_emision:
            !item.fecha_emision || item.fecha_emision === current.fecha_liquidacion
              ? value
              : item.fecha_emision,
          fecha_acreditacion:
            !item.fecha_acreditacion || item.fecha_acreditacion === current.fecha_liquidacion
              ? value
              : item.fecha_acreditacion,
        }));
      }
      if (field === "periodicidad" && current.tipo === "J") {
        next.fecha_primer_vencimiento = addMonths(
          current.fecha_liquidacion,
          value === "SEMESTRAL" ? 6 : 1,
        );
      }
      return next;
    });
    setSimulation(null);
    setError(null);
  };

  const updateCheck = (index, field, value) => {
    setForm((current) => ({
      ...current,
      cheques: current.cheques.map((item, itemIndex) =>
        itemIndex === index ? { ...item, [field]: value } : item,
      ),
    }));
    setSimulation(null);
    setError(null);
  };

  const addCheck = () => {
    setForm((current) => ({
      ...current,
      cheques: [...current.cheques, emptyCheck(current.fecha_liquidacion)],
    }));
    setSimulation(null);
  };

  const removeCheck = (index) => {
    setForm((current) => ({
      ...current,
      cheques:
        current.cheques.length === 1
          ? [emptyCheck(current.fecha_liquidacion)]
          : current.cheques.filter((_, itemIndex) => itemIndex !== index),
    }));
    setSimulation(null);
  };

  const payload = useMemo(
    () => ({
      tipo: form.tipo,
      id_asociado: Number(form.id_asociado),
      fecha_solicitud: form.fecha_solicitud,
      fecha_liquidacion: form.fecha_liquidacion,
      capital: numericValue(form.capital),
      plazo_dias: Number(form.plazo_dias),
      cantidad_cuotas: Number(form.cantidad_cuotas),
      periodicidad: form.periodicidad,
      fecha_primer_vencimiento: form.fecha_primer_vencimiento,
      rubro: form.rubro,
      destino: form.destino,
      detalle: form.detalle,
      observaciones: form.observaciones,
      tipo_garantia: form.tipo_garantia,
      garantes: [form.garante_1, form.garante_2].filter(Boolean).map(Number),
      gastos_administrativos: numericValue(form.gastos_administrativos),
      otros_gastos: numericValue(form.otros_gastos),
      recupero_gastos: numericValue(form.recupero_gastos),
      sellado: numericValue(form.sellado),
      seguro: numericValue(form.seguro),
      cheques: form.tipo === "A"
        ? form.cheques.map((item) => ({
            ...item,
            importe: numericValue(item.importe),
          }))
        : [],
    }),
    [form],
  );

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      if (!simulation) {
        const response = await simularAyuda(payload);
        setSimulation(response);
      } else {
        const response = await liquidarAyuda(payload);
        onLiquidated?.(response);
      }
    } catch (submitError) {
      setError(parseErrors(submitError));
    } finally {
      setBusy(false);
    }
  };

  const rateMissing = !activeRate;
  const quoteMissing = form.tipo === "I" && !quote;
  const fieldError = (name) => error?.fields?.[name];
  const checkCapital = form.cheques.reduce((sum, item) => sum + numericValue(item.importe), 0);

  return (
    <CrudModal
      footerStart={
        simulation ? (
          <button
            className="global-button global-button--ghost"
            disabled={busy}
            onClick={() => setSimulation(null)}
            type="button"
          >
            Modificar datos
          </button>
        ) : null
      }
      modalClassName="ayuda-liquidacion-modal"
      onClose={onClose}
      onSubmit={submit}
      open={open}
      saving={busy}
      savingLabel={simulation ? "Liquidando..." : "Calculando..."}
      submitDisabled={rateMissing || quoteMissing || catalogLoading}
      submitLabel={simulation ? "Liquidar y acreditar" : "Calcular liquidación"}
      subtitle="La operación genera el plan, el mutuo y acredita el importe correspondiente en la caja de ahorro común del socio."
      title="Nueva ayuda económica"
      wide
    >
      {error?.message ? (
        <div className="ayuda-alert is-error">
          <GlobalIcon name="error" size={18} />
          <span>{error.message}</span>
        </div>
      ) : null}

      {rateMissing ? (
        <div className="ayuda-alert is-warning">
          <GlobalIcon name="warning" size={18} />
          <span>No hay una tasa vigente para el grupo {group || "seleccionado"}. Cargala desde “Tasas y dólar”.</span>
        </div>
      ) : null}
      {quoteMissing ? (
        <div className="ayuda-alert is-warning">
          <GlobalIcon name="warning" size={18} />
          <span>No hay cotización del dólar para {form.fecha_liquidacion.slice(0, 7)}.</span>
        </div>
      ) : null}

      <div className="ayuda-form-section">
        <div className="ayuda-form-section__title">
          <strong>Liquidación</strong>
          <span>Datos principales de la ayuda y del socio.</span>
        </div>
        <div className="ayuda-form-grid ayuda-form-grid--4">
          <Field error={fieldError("tipo")} label="Tipo de ayuda" required>
            <select onChange={(event) => update("tipo", event.target.value)} value={form.tipo}>
              {(localCatalogs?.productos || []).map((item) => (
                <option key={item.codigo} value={item.codigo}>
                  {item.codigo} · {item.nombre}
                </option>
              ))}
            </select>
          </Field>
          <Field error={fieldError("id_asociado")} label="Socio" required className="is-span-2">
            <SearchableSelect
              ariaLabel="Buscar socio"
              clearLabel="SIN SOCIO SELECCIONADO"
              emptyMessage="NO SE ENCONTRARON SOCIOS"
              onChange={(value) => update("id_asociado", value)}
              options={associateOptions}
              placeholder="BUSCAR POR N° DE SOCIO, NOMBRE O DOCUMENTO..."
              value={form.id_asociado}
            />
          </Field>
          <Field label="Moneda">
            <input readOnly value={currency === "USD" ? "DÓLARES (USD)" : "PESOS (ARS)"} />
          </Field>
          <Field label="Fecha de solicitud" required>
            <input max={form.fecha_liquidacion || localDateValue()} type="date" value={form.fecha_solicitud} onChange={(event) => update("fecha_solicitud", event.target.value)} />
          </Field>
          <Field label="Fecha de liquidación" required>
            <input max={localDateValue()} min={form.fecha_solicitud || undefined} type="date" value={form.fecha_liquidacion} onChange={(event) => update("fecha_liquidacion", event.target.value)} />
          </Field>
          <Field label="TNA vigente">
            <input readOnly value={activeRate ? `${formatRate(activeRate.tna)} · BASE ${activeRate.base_dias || 365}` : "SIN CONFIGURAR"} />
          </Field>
          <Field label="Cotización del dólar">
            <input readOnly value={form.tipo === "I" ? (quote ? formatMoney(quote.valor_promedio, "ARS") : "SIN CONFIGURAR") : "NO APLICA"} />
          </Field>
        </div>
      </div>

      <div className="ayuda-form-section">
        <div className="ayuda-form-section__title">
          <strong>Condiciones financieras</strong>
          <span>Plazo, vencimiento, capital y sistema de amortización según el tipo.</span>
        </div>
        <div className="ayuda-form-grid ayuda-form-grid--4">
          {form.tipo !== "A" ? (
            <Field error={fieldError("capital")} label={`Capital (${currency})`} required>
              <input min="0" onChange={(event) => update("capital", event.target.value)} step="0.01" type="number" value={form.capital} />
            </Field>
          ) : (
            <Field label="Capital en cheques">
              <input readOnly value={formatMoney(checkCapital, "ARS")} />
            </Field>
          )}

          {form.tipo === "B" ? (
            <Field error={fieldError("plazo_dias")} label="Plazo" required>
              <select value={form.plazo_dias} onChange={(event) => update("plazo_dias", event.target.value)}>
                <option value="30">30 DÍAS</option>
                <option value="60">60 DÍAS</option>
              </select>
            </Field>
          ) : null}

          {form.tipo === "E" ? (
            <Field error={fieldError("cantidad_cuotas")} label="Cuotas" required>
              <select value={form.cantidad_cuotas} onChange={(event) => update("cantidad_cuotas", event.target.value)}>
                <option value="12">12 CUOTAS</option>
                <option value="18">18 CUOTAS</option>
                <option value="24">24 CUOTAS</option>
              </select>
            </Field>
          ) : null}

          {form.tipo === "I" || form.tipo === "J" ? (
            <Field error={fieldError("cantidad_cuotas")} label="Cantidad de cuotas" required>
              <input max="120" min="1" onChange={(event) => update("cantidad_cuotas", event.target.value)} type="number" value={form.cantidad_cuotas} />
            </Field>
          ) : null}

          {form.tipo === "J" ? (
            <Field label="Periodicidad" required>
              <select value={form.periodicidad} onChange={(event) => update("periodicidad", event.target.value)}>
                <option value="MENSUAL">MENSUAL</option>
                <option value="SEMESTRAL">SEMESTRAL</option>
              </select>
            </Field>
          ) : null}

          {["E", "I", "J"].includes(form.tipo) ? (
            <Field error={fieldError("fecha_primer_vencimiento")} label="Primer vencimiento" required>
              <input type="date" value={form.fecha_primer_vencimiento} onChange={(event) => update("fecha_primer_vencimiento", event.target.value)} />
            </Field>
          ) : null}

          <Field label="Sistema">
            <input
              readOnly
              value={
                form.tipo === "A"
                  ? "COMPRA DE VALORES"
                  : form.tipo === "B"
                    ? "AL VENCIMIENTO / RENOVABLE"
                    : form.tipo === "J"
                      ? "FRANCÉS"
                      : "DIRECTO"
              }
            />
          </Field>
          <Field label="Desembolso">
            <input readOnly value="CAJA DE AHORRO COMÚN" />
          </Field>
        </div>
      </div>

      {form.tipo === "A" ? (
        <div className="ayuda-form-section">
          <div className="ayuda-form-section__title ayuda-form-section__title--actions">
            <div>
              <strong>Valores / cheques</strong>
              <span>El devengamiento se calcula hasta la fecha de acreditación de cada cheque.</span>
            </div>
            <button className="global-button global-button--ghost" onClick={addCheck} type="button">
              <GlobalIcon name="plus" size={15} /> Agregar cheque
            </button>
          </div>
          <div className="ayuda-checks">
            {form.cheques.map((item, index) => (
              <article className="ayuda-check-card" key={`check-${index}`}>
                <header>
                  <strong>Cheque {index + 1}</strong>
                  <button aria-label="Quitar cheque" onClick={() => removeCheck(index)} type="button">
                    <GlobalIcon name="trash" size={15} />
                  </button>
                </header>
                <div className="ayuda-form-grid ayuda-form-grid--4">
                  <Field label="Banco" required><input value={item.banco} onChange={(event) => updateCheck(index, "banco", event.target.value)} /></Field>
                  <Field label="Sucursal"><input value={item.sucursal} onChange={(event) => updateCheck(index, "sucursal", event.target.value)} /></Field>
                  <Field label="Localidad"><input value={item.localidad} onChange={(event) => updateCheck(index, "localidad", event.target.value)} /></Field>
                  <Field label="Código postal"><input value={item.codigo_postal} onChange={(event) => updateCheck(index, "codigo_postal", event.target.value)} /></Field>
                  <Field label="N° cheque" required><input value={item.numero_cheque} onChange={(event) => updateCheck(index, "numero_cheque", event.target.value)} /></Field>
                  <Field label="Cuenta"><input value={item.cuenta} onChange={(event) => updateCheck(index, "cuenta", event.target.value)} /></Field>
                  <Field label="CUIT librador"><input value={item.cuit_librador} onChange={(event) => updateCheck(index, "cuit_librador", event.target.value)} /></Field>
                  <Field label="Importe" required><input min="0" step="0.01" type="number" value={item.importe} onChange={(event) => updateCheck(index, "importe", event.target.value)} /></Field>
                  <Field label="Fecha de emisión" required><input type="date" value={item.fecha_emision} onChange={(event) => updateCheck(index, "fecha_emision", event.target.value)} /></Field>
                  <Field label="Fecha de acreditación" required><input type="date" value={item.fecha_acreditacion} onChange={(event) => updateCheck(index, "fecha_acreditacion", event.target.value)} /></Field>
                  <label className="ayuda-check-toggle"><input checked={item.endosado} type="checkbox" onChange={(event) => updateCheck(index, "endosado", event.target.checked)} /><span>Endosado</span></label>
                  <label className="ayuda-check-toggle"><input checked={item.electronico} type="checkbox" onChange={(event) => updateCheck(index, "electronico", event.target.checked)} /><span>Cheque electrónico</span></label>
                </div>
              </article>
            ))}
          </div>
        </div>
      ) : null}

      <div className="ayuda-form-section">
        <div className="ayuda-form-section__title">
          <strong>Rubro, destino y garantía</strong>
          <span>Campos presentes en la liquidación y en el mutuo.</span>
        </div>
        <div className="ayuda-form-grid ayuda-form-grid--4">
          <Field error={fieldError("rubro")} label="Rubro" required>
            <input
              list="ayuda-rubros-sugeridos"
              value={form.rubro}
              onChange={(event) => update("rubro", event.target.value)}
            />
            <datalist id="ayuda-rubros-sugeridos">
              {(localCatalogs?.rubros || []).map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
            </datalist>
          </Field>
          <Field error={fieldError("destino")} label="Destino" required className="is-span-2">
            <input value={form.destino} onChange={(event) => update("destino", event.target.value)} placeholder="Ej.: ADQUISICIÓN DE MERCADERÍA" />
          </Field>
          <Field error={fieldError("tipo_garantia")} label="Tipo de garantía" required>
            <input
              list="ayuda-garantias-sugeridas"
              value={form.tipo_garantia}
              onChange={(event) => update("tipo_garantia", event.target.value)}
            />
            <datalist id="ayuda-garantias-sugeridas">
              {(localCatalogs?.tipos_garantia || []).map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
            </datalist>
          </Field>
          <Field label="Garante 1" className="is-span-2">
            <SearchableSelect
              ariaLabel="Buscar primer garante"
              clearLabel="SIN GARANTE"
              emptyMessage="NO SE ENCONTRARON PERSONAS"
              onChange={(value) => update("garante_1", value)}
              options={guarantorOptions
                .filter((item) => Number(item.id) !== Number(form.garante_2 || 0))
                .map(toGuarantorOption)}
              placeholder="BUSCAR GARANTE POR NOMBRE O DOCUMENTO..."
              value={form.garante_1}
            />
          </Field>
          <Field label="Garante 2" className="is-span-2">
            <SearchableSelect
              ariaLabel="Buscar segundo garante"
              clearLabel="SIN SEGUNDO GARANTE"
              emptyMessage="NO SE ENCONTRARON PERSONAS"
              onChange={(value) => update("garante_2", value)}
              options={guarantorOptions
                .filter((item) => Number(item.id) !== Number(form.garante_1 || 0))
                .map(toGuarantorOption)}
              placeholder="BUSCAR GARANTE POR NOMBRE O DOCUMENTO..."
              value={form.garante_2}
            />
          </Field>
          <Field label="Detalle" className="is-span-2"><input value={form.detalle} onChange={(event) => update("detalle", event.target.value)} /></Field>
          <Field label="Observaciones" className="is-span-2"><input value={form.observaciones} onChange={(event) => update("observaciones", event.target.value)} /></Field>
        </div>
      </div>

      <div className="ayuda-form-section">
        <div className="ayuda-form-section__title">
          <strong>Gastos y seguro</strong>
          <span>Se distribuyen en el plan; en la compra de cheques se descuentan del importe acreditado.</span>
        </div>
        <div className="ayuda-form-grid ayuda-form-grid--5">
          {[
            ["gastos_administrativos", "Gastos administrativos"],
            ["otros_gastos", "Otros gastos"],
            ["recupero_gastos", "Recupero de gastos"],
            ["sellado", "Sellado"],
            ["seguro", "Seguro"],
          ].map(([key, label]) => (
            <Field key={key} label={`${label} (${currency})`}>
              <input min="0" step="0.01" type="number" value={form[key]} onChange={(event) => update(key, event.target.value)} />
            </Field>
          ))}
        </div>
      </div>

      {simulation ? (
        <div className="ayuda-simulation">
          <div className="ayuda-simulation__head">
            <div>
              <strong>Liquidación calculada</strong>
              <span>{simulation.producto?.codigo} · {simulation.producto?.nombre}</span>
            </div>
            <span className="global-chip is-success">LISTA PARA LIQUIDAR</span>
          </div>
          <div className="ayuda-summary-grid">
            <div><span>Capital</span><strong>{formatMoney(simulation.resumen?.capital_original, simulation.producto?.moneda)}</strong></div>
            <div><span>Devengamiento</span><strong>{formatMoney(simulation.resumen?.devengamiento_total, simulation.producto?.moneda)}</strong></div>
            <div><span>Total</span><strong>{formatMoney(simulation.resumen?.total_a_devolver, simulation.producto?.moneda)}</strong></div>
            <div><span>Acreditación en caja común</span><strong>{formatMoney(simulation.resumen?.importe_acreditado_ars, "ARS")}</strong></div>
            <div><span>TEM / TNA</span><strong>{formatRate(simulation.resumen?.tem)} / {formatRate(simulation.resumen?.tna)}</strong></div>
            <div><span>TEA / CFT</span><strong>{formatRate(simulation.resumen?.tea)} / {formatRate(simulation.resumen?.cft)}</strong></div>
            <div><span>Primer vencimiento</span><strong>{formatDate(simulation.campos?.fecha_primer_vencimiento)}</strong></div>
            <div><span>Vencimiento final</span><strong>{formatDate(simulation.campos?.fecha_vencimiento)}</strong></div>
          </div>
          {simulation.cuotas?.length ? (
            <div className="ayuda-plan-preview">
              <table>
                <thead><tr><th>Cuota</th><th>Vencimiento</th><th>Amortización</th><th>Interés</th><th>Total</th></tr></thead>
                <tbody>
                  {simulation.cuotas.slice(0, 6).map((item) => (
                    <tr key={item.numero_cuota}>
                      <td>{item.numero_cuota}</td><td>{formatDate(item.fecha_vencimiento)}</td>
                      <td>{formatMoney(item.amortizacion_capital, simulation.producto?.moneda)}</td>
                      <td>{formatMoney(item.devengamiento, simulation.producto?.moneda)}</td>
                      <td>{formatMoney(item.importe_cuota, simulation.producto?.moneda)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {simulation.cuotas.length > 6 ? <small>Se muestran las primeras 6 de {simulation.cuotas.length} cuotas. El plan completo queda guardado.</small> : null}
            </div>
          ) : null}
        </div>
      ) : null}
    </CrudModal>
  );
}
