import React, { useCallback, useEffect, useMemo, useState } from "react";
import { useAuth } from "../../Login/context/AuthProvider";
import CrudModal from "../../Global/components/CrudModal";
import GlobalIcon from "../../Global/components/GlobalIcon";
import { EntityTabs } from "../../Global/components/TabbedForm";
import {
  generarInformeRiesgo,
  getInformeRiesgoDetalle,
  getInformesRiesgo,
  guardarDictamenRiesgo,
  guardarEvaluacionUif,
  refrescarFuenteRepet,
  refrescarFuentesBcra,
} from "../api/ayudasApi";
import "./InformeRiesgoModal.css";

const SOURCE_NAMES = {
  BCRA_DEUDA_ACTUAL: "BCRA · deuda actual",
  BCRA_HISTORICO: "BCRA · historial 24 meses",
  BCRA_CHEQUES_RECHAZADOS: "BCRA · cheques rechazados",
  REPET: "RePET · control automático",
};

const initialUif = () => ({
  actividad: "",
  proposito: "",
  origen_fondos: "",
  monto_solicitado: "",
  ingresos_mensuales: "",
  patrimonio_estimado: "",
  identidad_verificada: false,
  documentacion_completa: false,
  origen_fondos_documentado: false,
  pep_estado: "NO_INFORMA",
  terrorismo_resultado: "PENDIENTE",
  no_residente: false,
  jurisdiccion_riesgo: false,
  efectivo_intensivo: false,
  fondos_terceros: false,
  datos_contradictorios: false,
  comportamiento_inusual: false,
  observaciones: "",
});

const initialDecision = () => ({
  resultado: "REVISION",
  fundamento: "",
  condiciones: "",
});

const money = (value) =>
  new Intl.NumberFormat("es-AR", {
    style: "currency",
    currency: "ARS",
    maximumFractionDigits: 2,
  }).format(Number(value || 0));

const integer = (value) =>
  new Intl.NumberFormat("es-AR", { maximumFractionDigits: 0 }).format(
    Number(value || 0),
  );

const dateTime = (value) => {
  if (!value) return "—";
  const normalized = String(value).replace(" ", "T");
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleString("es-AR");
};

const readable = (value, fallback = "—") =>
  value === null || value === undefined || value === ""
    ? fallback
    : String(value).replaceAll("_", " ");

const tone = (value) => {
  const normalized = String(value || "").toUpperCase();
  if (["BAJO", "OK", "RECOMENDADO", "SIN_COINCIDENCIA"].includes(normalized)) {
    return "is-good";
  }
  if (
    [
      "ALTO",
      "ERROR",
      "NO_RECOMENDADO",
      "NO_CONTINUAR_DD",
      "CRITICA",
      "COINCIDENCIA_POTENCIAL",
    ].includes(normalized)
  ) {
    return "is-danger";
  }
  if (
    [
      "MEDIO",
      "NO_DISPONIBLE",
      "VENCIDA",
      "OBSERVADO",
      "CONDICIONADO",
      "REVISION",
      "ALTA",
    ].includes(normalized)
  ) {
    return "is-warning";
  }
  return "is-neutral";
};

function RiskBadge({ value }) {
  return (
    <span className={`risk-report-badge ${tone(value)}`}>
      {readable(value, "NO DETERMINADO")}
    </span>
  );
}

function Feedback({ value, onClose }) {
  if (!value) return null;
  return (
    <div className={`risk-report-feedback is-${value.type || "info"}`}>
      <GlobalIcon
        name={
          value.type === "success"
            ? "check"
            : value.type === "warning"
              ? "warning"
              : value.type === "error"
                ? "error"
                : "info"
        }
        size={18}
      />
      <span>{value.message}</span>
      <button
        aria-label="Cerrar aviso"
        className="global-button global-button--ghost risk-report-icon-button"
        onClick={onClose}
        type="button"
      >
        <GlobalIcon name="close" size={14} />
      </button>
    </div>
  );
}

function SummaryCard({ label, value, detail, badge = false }) {
  return (
    <article className="risk-report-summary-card">
      <span>{label}</span>
      {badge ? <RiskBadge value={value} /> : <strong>{value}</strong>}
      {detail ? <small>{detail}</small> : null}
    </article>
  );
}

function BooleanField({ checked, label, onChange }) {
  return (
    <label className="risk-report-check">
      <input checked={checked} onChange={onChange} type="checkbox" />
      <span>{label}</span>
    </label>
  );
}

function EmptySource({ source }) {
  return (
    <div className="risk-report-empty">
      <GlobalIcon
        name={source?.estado === "SIN_DATOS" ? "info" : "warning"}
        size={24}
      />
      <strong>{SOURCE_NAMES[source?.fuente] || "Fuente externa"}</strong>
      <span>
        {source?.estado === "SIN_DATOS"
          ? "El BCRA no informó registros para este CUIT en esta consulta. Esto no certifica que la persona no tenga deudas u obligaciones."
          : source?.error_mensaje ||
            "La fuente no está disponible en este momento. El resto del informe se conserva."}
      </span>
      {source?.estado ? <RiskBadge value={source.estado} /> : null}
    </div>
  );
}

export default function InformeRiesgoModal({ open, onClose, socios = [] }) {
  const { can } = useAuth();
  const canGenerate = can("ayudas.informes.generate");
  const canEvaluate = can("ayudas.informes.evaluate");
  const canDecide = can("ayudas.informes.decide");
  const canRefresh = can("ayudas.informes.refresh");

  const [selection, setSelection] = useState({
    id_persona: "",
    cuit: "",
    denominacion: "",
  });
  const [history, setHistory] = useState([]);
  const [detail, setDetail] = useState(null);
  const [activeTab, setActiveTab] = useState("summary");
  const [uif, setUif] = useState(() => initialUif());
  const [decision, setDecision] = useState(() => initialDecision());
  const [busy, setBusy] = useState("");
  const [feedback, setFeedback] = useState(null);

  const loadHistory = useCallback(async () => {
    try {
      const response = await getInformesRiesgo({ limite: 30 });
      setHistory(Array.isArray(response?.items) ? response.items : []);
    } catch (error) {
      setFeedback({
        type: "error",
        message:
          error?.message || "No se pudo cargar el historial de informes.",
      });
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    setDetail(null);
    setActiveTab("summary");
    setFeedback(null);
    setSelection({ id_persona: "", cuit: "", denominacion: "" });
    setDecision(initialDecision());
    loadHistory();
  }, [loadHistory, open]);

  useEffect(() => {
    if (!detail) return;
    const evaluation = detail.evaluacion_uif;
    const person = detail.informe?.antecedentes?.persona || {};
    if (evaluation) {
      const next = initialUif();
      Object.keys(next).forEach((key) => {
        if (evaluation[key] !== undefined && evaluation[key] !== null) {
          next[key] = evaluation[key];
        }
      });
      setUif(next);
    } else {
      setUif({
        ...initialUif(),
        actividad: person.actividad || "",
        ingresos_mensuales: person.ingresos_mensuales || "",
        patrimonio_estimado: person.patrimonio_estimado || "",
        pep_estado: person.es_pep ? "NACIONAL" : "NO_INFORMA",
        no_residente: person.residente === false || person.residente === 0,
      });
    }
    if (detail.dictamen) {
      setDecision({
        resultado: detail.dictamen.resultado || "REVISION",
        fundamento: detail.dictamen.fundamento || "",
        condiciones: detail.dictamen.condiciones || "",
      });
    } else {
      setDecision(initialDecision());
    }
  }, [detail]);

  const sources = useMemo(() => {
    const indexed = {};
    (detail?.fuentes || []).forEach((source) => {
      indexed[source.fuente] = source;
    });
    return indexed;
  }, [detail]);

  const current = sources.BCRA_DEUDA_ACTUAL;
  const historical = sources.BCRA_HISTORICO;
  const rejected = sources.BCRA_CHEQUES_RECHAZADOS;
  const repetSource = sources.REPET;
  const repetSummary = repetSource?.normalizado?.resumen || {};
  const report = detail?.informe || {};
  const background = report.antecedentes || {};

  const openReport = async (id) => {
    setBusy("open");
    setFeedback(null);
    try {
      const response = await getInformeRiesgoDetalle(id);
      setDetail(response);
      setActiveTab("summary");
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo abrir el informe.",
      });
    } finally {
      setBusy("");
    }
  };

  const generate = async () => {
    if (!selection.id_persona && !selection.cuit.trim()) {
      setFeedback({
        type: "warning",
        message: "Seleccioná un socio o ingresá un CUIT válido.",
      });
      return;
    }
    setBusy("generate");
    setFeedback(null);
    try {
      const response = await generarInformeRiesgo({
        id_persona: selection.id_persona
          ? Number(selection.id_persona)
          : undefined,
        cuit: selection.cuit.trim() || undefined,
        denominacion: selection.denominacion.trim() || undefined,
      });
      setDetail(response);
      setActiveTab("summary");
      await loadHistory();
      setFeedback({
        type: "success",
        message:
          "Informe generado. BCRA y RePET se consultaron automáticamente; completá la evaluación LA/FT antes del dictamen.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo generar el informe.",
      });
    } finally {
      setBusy("");
    }
  };

  const refresh = async () => {
    setBusy("refresh");
    setFeedback(null);
    try {
      const response = await refrescarFuentesBcra(report.id_informe);
      setDetail(response);
      await loadHistory();
      setFeedback({
        type: "success",
        message:
          "Las tres fuentes BCRA se volvieron a consultar y el resumen crediticio fue recalculado.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message:
          error?.message || "No se pudieron actualizar las fuentes BCRA.",
      });
    } finally {
      setBusy("");
    }
  };

  const saveUif = async () => {
    setBusy("uif");
    setFeedback(null);
    try {
      const response = await guardarEvaluacionUif(report.id_informe, {
        ...uif,
        monto_solicitado: Number(uif.monto_solicitado || 0),
        ingresos_mensuales: Number(uif.ingresos_mensuales || 0),
        patrimonio_estimado: Number(uif.patrimonio_estimado || 0),
      });
      setDetail(response);
      await loadHistory();
      setFeedback({
        type: "success",
        message:
          "Evaluación LA/FT guardada con su versión de reglas, factores y alertas explicables.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo guardar la evaluación UIF.",
      });
    } finally {
      setBusy("");
    }
  };

  const refreshRepet = async () => {
    setBusy("repet");
    setFeedback(null);
    try {
      const response = await refrescarFuenteRepet(report.id_informe);
      setDetail(response);
      await loadHistory();
      setFeedback({
        type: "success",
        message:
          "Los listados oficiales de RePET se volvieron a descargar y controlar.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo actualizar el control RePET.",
      });
    } finally {
      setBusy("");
    }
  };

  const saveDecision = async () => {
    setBusy("decision");
    setFeedback(null);
    try {
      const response = await guardarDictamenRiesgo(report.id_informe, decision);
      setDetail(response);
      await loadHistory();
      setFeedback({
        type: "success",
        message:
          "Dictamen humano registrado. El historial anterior permanece preservado.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo registrar el dictamen.",
      });
    } finally {
      setBusy("");
    }
  };

  const setUifValue = (key, value) =>
    setUif((currentValue) => ({ ...currentValue, [key]: value }));

  const reportTabs = [
    { value: "summary", label: "Resumen" },
    { value: "bcra", label: "BCRA", badge: 3 },
    {
      value: "repet",
      label: "RePET",
      badge: Number(repetSummary.cantidad_coincidencias_potenciales || 0),
    },
    { value: "identity", label: "Identidad" },
    {
      value: "uif",
      label: "UIF / LA-FT",
      badge: detail?.alertas?.length || 0,
    },
    { value: "decision", label: "Dictamen" },
  ];

  return (
    <CrudModal
      footerStart={
        detail ? (
          <div className="risk-report-footer-actions">
            <button
              className="global-button global-button--ghost"
              onClick={() => {
                setDetail(null);
                setFeedback(null);
              }}
              type="button"
            >
              <GlobalIcon name="plus" size={16} /> Nuevo informe
            </button>
            <button
              className="global-button global-button--ghost"
              onClick={() => window.print()}
              type="button"
            >
              <GlobalIcon name="print" size={16} /> Imprimir
            </button>
            {canRefresh ? (
              <>
                <button
                  className="global-button global-button--ghost"
                  disabled={Boolean(busy)}
                  onClick={refresh}
                  type="button"
                >
                  <GlobalIcon
                    className={busy === "refresh" ? "is-spinning" : ""}
                    name="refresh"
                    size={16}
                  />
                  Actualizar BCRA
                </button>
                <button
                  className="global-button global-button--ghost"
                  disabled={Boolean(busy)}
                  onClick={refreshRepet}
                  type="button"
                >
                  <GlobalIcon
                    className={busy === "repet" ? "is-spinning" : ""}
                    name="refresh"
                    size={16}
                  />
                  Actualizar RePET
                </button>
              </>
            ) : null}
          </div>
        ) : null
      }
      hideSubmit
      modalClassName="informe-risk-modal"
      onClose={onClose}
      onSubmit={(event) => event.preventDefault()}
      open={open}
      saving={Boolean(busy)}
      subtitle={
        detail
          ? `${report.denominacion || "Persona consultada"} · ${report.cuit_cuil_enmascarado || ""}`
          : "BCRA y RePET automáticos + evaluación LA/FT interna"
      }
      title={
        detail
          ? `Informe integral N° ${report.id_informe || "—"}`
          : "Informe integral por CUIT"
      }
      wide
    >
      <Feedback value={feedback} onClose={() => setFeedback(null)} />

      {!detail ? (
        <div className="risk-report-start">
          <section className="risk-report-generate">
            <header>
              <span>Nuevo análisis</span>
              <h3>Consultá por CUIT</h3>
              <p>
                Podés vincular el informe a un socio del sistema o analizar un
                CUIT sin legajo interno.
              </p>
            </header>
            <div className="risk-report-form-grid">
              <label className="entity-field is-active">
                <span>Socio / persona registrada</span>
                <select
                  disabled={!canGenerate || Boolean(busy)}
                  onChange={(event) =>
                    setSelection((currentValue) => ({
                      ...currentValue,
                      id_persona: event.target.value,
                      cuit: event.target.value ? "" : currentValue.cuit,
                      denominacion: event.target.value
                        ? ""
                        : currentValue.denominacion,
                    }))
                  }
                  value={selection.id_persona}
                >
                  <option value="">Sin vincular / ingresar CUIT</option>
                  {socios.map((item) => (
                    <option key={item.id_persona} value={item.id_persona}>
                      N° {item.numero_socio} · {item.nombre} ·{" "}
                      {item.documento || "sin documento"}
                    </option>
                  ))}
                </select>
              </label>
              <label className="entity-field is-active">
                <span>CUIT / CUIL / CDI</span>
                <input
                  disabled={
                    !canGenerate ||
                    Boolean(busy) ||
                    Boolean(selection.id_persona)
                  }
                  inputMode="numeric"
                  maxLength={15}
                  onChange={(event) =>
                    setSelection((currentValue) => ({
                      ...currentValue,
                      cuit: event.target.value,
                    }))
                  }
                  placeholder="Ej.: 30-12345678-9"
                  value={selection.cuit}
                />
              </label>
              <label className="entity-field is-active is-span-2">
                <span>Nombre / razón social (para RePET)</span>
                <input
                  disabled={
                    !canGenerate ||
                    Boolean(busy) ||
                    Boolean(selection.id_persona)
                  }
                  maxLength={240}
                  onChange={(event) =>
                    setSelection((currentValue) => ({
                      ...currentValue,
                      denominacion: event.target.value,
                    }))
                  }
                  placeholder="Opcional si BCRA devuelve la denominación; recomendado para CUIT externos"
                  value={selection.denominacion}
                />
              </label>
            </div>
            <button
              className="global-button global-button--primary"
              disabled={!canGenerate || Boolean(busy)}
              onClick={generate}
              type="button"
            >
              <GlobalIcon
                className={busy === "generate" ? "is-spinning" : ""}
                name={busy === "generate" ? "loader" : "shield"}
                size={17}
              />
              {busy === "generate"
                ? "Consultando fuentes..."
                : "Generar informe"}
            </button>
            {!canGenerate ? (
              <small className="risk-report-permission">
                Tu perfil puede consultar informes existentes, pero no generar
                uno nuevo.
              </small>
            ) : null}
          </section>

          <section className="risk-report-history">
            <header>
              <div>
                <span>Auditoría</span>
                <h3>Informes recientes</h3>
              </div>
              <button
                aria-label="Actualizar historial"
                className="global-button global-button--ghost risk-report-icon-button"
                disabled={Boolean(busy)}
                onClick={loadHistory}
                type="button"
              >
                <GlobalIcon name="refresh" size={16} />
              </button>
            </header>
            <div className="risk-report-history-list">
              {!history.length ? (
                <div className="risk-report-empty is-compact">
                  <GlobalIcon name="inbox" size={22} />
                  <span>No hay informes disponibles.</span>
                </div>
              ) : (
                history.map((item) => (
                  <button
                    className="global-button global-button--ghost risk-report-history-item"
                    disabled={Boolean(busy)}
                    key={item.id_informe}
                    onClick={() => openReport(item.id_informe)}
                    type="button"
                  >
                    <span>
                      <strong>
                        N° {item.id_informe} ·{" "}
                        {item.denominacion || item.cuit_cuil_enmascarado}
                      </strong>
                      <small>
                        {item.cuit_cuil_enmascarado} ·{" "}
                        {dateTime(item.creado_en)}
                      </small>
                    </span>
                    <span>
                      <RiskBadge value={item.estado} />
                      <GlobalIcon name="eye" size={16} />
                    </span>
                  </button>
                ))
              )}
            </div>
          </section>
        </div>
      ) : (
        <div className="risk-report-workspace">
          <div className="risk-report-disclaimer">
            <GlobalIcon name="info" size={18} />
            <span>{detail.advertencia_regulatoria}</span>
          </div>

          <EntityTabs
            ariaLabel="Secciones del informe integral"
            idPrefix="informe-riesgo-tab"
            onChange={setActiveTab}
            tabs={reportTabs}
            value={activeTab}
          />

          <div className="risk-report-panel">
            {activeTab === "summary" ? (
              <div className="risk-report-summary">
                <div className="risk-report-summary-grid">
                  <SummaryCard
                    badge
                    detail="BCRA e historial crediticio"
                    label="Riesgo crediticio"
                    value={report.riesgo_crediticio}
                  />
                  <SummaryCard
                    badge
                    detail="Segmentación interna LA/FT"
                    label="Riesgo UIF"
                    value={report.riesgo_uif}
                  />
                  <SummaryCard
                    badge
                    detail="Estado del legajo analizado"
                    label="Documentación"
                    value={report.documentacion_estado}
                  />
                  <SummaryCard
                    badge
                    detail={
                      detail.dictamen
                        ? `Por ${detail.dictamen.dictaminado_por_nombre || "usuario autorizado"}`
                        : "Requiere decisión humana"
                    }
                    label="Dictamen"
                    value={detail.dictamen?.resultado || "PENDIENTE"}
                  />
                </div>

                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Trazabilidad</span>
                      <h3>Fuentes consultadas</h3>
                    </div>
                    <small>
                      Hash del snapshot integral:{" "}
                      {report.hash_integridad || "—"}
                    </small>
                  </header>
                  <div className="risk-report-source-list">
                    {(detail.fuentes || []).map((source) => (
                      <article key={source.fuente}>
                        <span>
                          <strong>
                            {SOURCE_NAMES[source.fuente] || source.fuente}
                          </strong>
                          <small>
                            {dateTime(source.consultado_en)}
                            {source.es_cache ? " · caché vigente" : ""}
                          </small>
                        </span>
                        <RiskBadge value={source.estado} />
                      </article>
                    ))}
                  </div>
                </section>

                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Sistema interno</span>
                      <h3>Antecedentes del legajo</h3>
                    </div>
                  </header>
                  <div className="risk-report-kpis">
                    <SummaryCard
                      label="Ayudas históricas"
                      value={integer(background.ayudas?.cantidad_total)}
                    />
                    <SummaryCard
                      label="Ayudas vigentes"
                      value={integer(background.ayudas?.cantidad_vigentes)}
                    />
                    <SummaryCard
                      label="Cuotas vencidas"
                      value={integer(background.ayudas?.cuotas_vencidas)}
                    />
                    <SummaryCard
                      label="Documentos vencidos"
                      value={integer(background.documentos?.vencidos)}
                    />
                    <SummaryCard
                      label="Vínculos PEP"
                      value={integer(background.vinculos_pep)}
                    />
                  </div>
                </section>
              </div>
            ) : null}

            {activeTab === "bcra" ? (
              <div className="risk-report-bcra">
                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Central de Deudores</span>
                      <h3>Situación actual</h3>
                    </div>
                    {current ? <RiskBadge value={current.estado} /> : null}
                  </header>
                  {current?.estado === "OK" ? (
                    <>
                      <div className="risk-report-kpis">
                        <SummaryCard
                          label="Deuda informada"
                          value={money(
                            current.normalizado?.resumen
                              ?.deuda_total_pesos_estimada,
                          )}
                          detail="BCRA publica montos en miles de pesos"
                        />
                        <SummaryCard
                          label="Peor situación"
                          value={
                            current.normalizado?.resumen
                              ?.peor_situacion_descripcion ||
                            "Sin clasificación"
                          }
                        />
                        <SummaryCard
                          label="Atraso máximo"
                          value={`${integer(
                            current.normalizado?.resumen?.dias_atraso_maximo,
                          )} días`}
                        />
                        <SummaryCard
                          label="Entidades"
                          value={integer(
                            current.normalizado?.resumen?.cantidad_entidades,
                          )}
                        />
                      </div>
                      <div className="risk-report-table-wrap">
                        <table>
                          <thead>
                            <tr>
                              <th>Entidad</th>
                              <th>Situación</th>
                              <th className="is-right">Monto estimado</th>
                              <th className="is-right">Atraso</th>
                              <th>Indicadores</th>
                            </tr>
                          </thead>
                          <tbody>
                            {(current.normalizado?.entidades || []).map(
                              (item, index) => (
                                <tr key={`${item.entidad}-${index}`}>
                                  <td>{item.entidad}</td>
                                  <td>
                                    {item.situacion} ·{" "}
                                    {item.situacion_descripcion}
                                  </td>
                                  <td className="is-right">
                                    {money(item.monto_pesos_estimados)}
                                  </td>
                                  <td className="is-right">
                                    {integer(item.dias_atraso)} días
                                  </td>
                                  <td>
                                    {[
                                      item.proceso_judicial
                                        ? "Proceso judicial"
                                        : "",
                                      item.refinanciaciones
                                        ? "Refinanciación"
                                        : "",
                                      item.en_revision ? "En revisión" : "",
                                    ]
                                      .filter(Boolean)
                                      .join(" · ") || "Sin alertas"}
                                  </td>
                                </tr>
                              ),
                            )}
                          </tbody>
                        </table>
                      </div>
                    </>
                  ) : (
                    <EmptySource source={current} />
                  )}
                </section>

                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Evolución</span>
                      <h3>Historial de 24 meses</h3>
                    </div>
                    {historical ? (
                      <RiskBadge value={historical.estado} />
                    ) : null}
                  </header>
                  {historical?.estado === "OK" ? (
                    <div className="risk-report-table-wrap">
                      <table>
                        <thead>
                          <tr>
                            <th>Período</th>
                            <th>Peor situación</th>
                            <th className="is-right">Deuda total estimada</th>
                            <th className="is-right">Entidades</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(historical.normalizado?.periodos || []).map(
                            (period) => (
                              <tr key={period.periodo}>
                                <td>{period.periodo}</td>
                                <td>
                                  {period.peor_situacion_descripcion ||
                                    "Sin clasificación"}
                                </td>
                                <td className="is-right">
                                  {money(period.deuda_total_pesos_estimada)}
                                </td>
                                <td className="is-right">
                                  {integer(period.entidades?.length)}
                                </td>
                              </tr>
                            ),
                          )}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <EmptySource source={historical} />
                  )}
                </section>

                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Valores</span>
                      <h3>Cheques rechazados</h3>
                    </div>
                    {rejected ? <RiskBadge value={rejected.estado} /> : null}
                  </header>
                  {rejected?.estado === "OK" ? (
                    <div className="risk-report-table-wrap">
                      <table>
                        <thead>
                          <tr>
                            <th>Número</th>
                            <th>Fecha rechazo</th>
                            <th>Causal</th>
                            <th className="is-right">Monto</th>
                            <th>Pago</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(rejected.normalizado?.cheques || []).map(
                            (item, index) => (
                              <tr key={`${item.numero_cheque}-${index}`}>
                                <td>{item.numero_cheque || "—"}</td>
                                <td>{item.fecha_rechazo || "—"}</td>
                                <td>{item.causal || "—"}</td>
                                <td className="is-right">
                                  {money(item.monto_pesos)}
                                </td>
                                <td>{item.fecha_pago || "Pendiente"}</td>
                              </tr>
                            ),
                          )}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <EmptySource source={rejected} />
                  )}
                </section>
              </div>
            ) : null}

            {activeTab === "repet" ? (
              <div className="risk-report-repet">
                <div className="risk-report-legal-note">
                  <GlobalIcon name="shield" size={20} />
                  <span>
                    El control compara automáticamente nombres, razones sociales
                    y vínculos contra los JSON oficiales de RePET. Una
                    coincidencia es potencial hasta que Cumplimiento confirme
                    identidad; nunca produce rechazo automático.
                  </span>
                </div>
                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Fuente pública oficial</span>
                      <h3>Registro Público de Personas y Entidades</h3>
                    </div>
                    {repetSource ? (
                      <RiskBadge value={repetSource.estado} />
                    ) : null}
                  </header>
                  {!repetSource || repetSource.estado === "NO_DISPONIBLE" ? (
                    <EmptySource source={repetSource} />
                  ) : (
                    <>
                      <div className="risk-report-summary-grid">
                        <SummaryCard
                          badge
                          label="Resultado"
                          value={repetSummary.resultado}
                        />
                        <SummaryCard
                          label="Nombres controlados"
                          value={integer(
                            repetSummary.cantidad_nombres_controlados,
                          )}
                        />
                        <SummaryCard
                          label="Coincidencias potenciales"
                          value={integer(
                            repetSummary.cantidad_coincidencias_potenciales,
                          )}
                        />
                        <SummaryCard
                          label="Actualizado"
                          value={dateTime(repetSource.consultado_en)}
                          detail={
                            repetSource.es_cache
                              ? "Caché vigente"
                              : "Descarga directa"
                          }
                        />
                      </div>
                      <div className="risk-report-table-wrap">
                        <table>
                          <thead>
                            <tr>
                              <th>Nombre controlado</th>
                              <th>Rol</th>
                              <th>Tipo</th>
                              <th>Resultado</th>
                            </tr>
                          </thead>
                          <tbody>
                            {(repetSource.normalizado?.consultas || []).map(
                              (query) => (
                                <tr key={`${query.rol}-${query.nombre}`}>
                                  <td>{query.nombre}</td>
                                  <td>{readable(query.rol)}</td>
                                  <td>{readable(query.tipo)}</td>
                                  <td>
                                    <RiskBadge value={query.resultado} />
                                  </td>
                                </tr>
                              ),
                            )}
                          </tbody>
                        </table>
                      </div>
                      {(repetSource.normalizado?.coincidencias || []).length ? (
                        <div className="risk-report-alert-list">
                          {repetSource.normalizado.coincidencias.map(
                            (match, index) => (
                              <article key={`${match.data_id}-${index}`}>
                                <RiskBadge value="REVISION" />
                                <div>
                                  <strong>{match.nombre}</strong>
                                  <span>
                                    Consultado como {match.consulta} · similitud{" "}
                                    {Math.round(
                                      Number(match.puntaje || 0) * 100,
                                    )}
                                    %
                                  </span>
                                  <small>
                                    {readable(match.tipo_registro)} · referencia{" "}
                                    {match.referencia || "sin referencia"}
                                  </small>
                                </div>
                              </article>
                            ),
                          )}
                        </div>
                      ) : null}
                    </>
                  )}
                  <p className="risk-report-caption">
                    Hash personas: {repetSummary.hash_personas || "—"} · Hash
                    entidades: {repetSummary.hash_entidades || "—"}
                  </p>
                  <a
                    href={detail.fuentes_oficiales?.repet}
                    rel="noreferrer"
                    target="_blank"
                  >
                    Abrir RePET oficial <GlobalIcon name="external" size={14} />
                  </a>
                </section>
              </div>
            ) : null}

            {activeTab === "identity" ? (
              <div className="risk-report-identity">
                <div className="risk-report-legal-note">
                  <GlobalIcon name="info" size={20} />
                  <span>
                    RENAPER no es una API pública libre. La conexión real queda
                    pendiente hasta que la Mutual adhiera a SID, defina el
                    servicio y entregue credenciales autorizadas.
                  </span>
                </div>
                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Estado de integración</span>
                      <h3>Identidad y RENAPER / SID</h3>
                    </div>
                    <RiskBadge value="PENDIENTE" />
                  </header>
                  <div className="risk-report-summary-grid">
                    <SummaryCard
                      label="Legajo interno"
                      value={
                        background.persona_encontrada
                          ? "Vinculado"
                          : "Sin vincular"
                      }
                    />
                    <SummaryCard
                      label="Documentos registrados"
                      value={integer(background.documentos?.cantidad_total)}
                    />
                    <SummaryCard
                      label="Documentos vigentes"
                      value={integer(background.documentos?.vigentes)}
                    />
                    <SummaryCard
                      label="Validación RENAPER"
                      value="Pendiente de la Mutual"
                    />
                  </div>
                  <div className="risk-report-pending">
                    <strong>Para habilitar RENAPER la Mutual debe:</strong>
                    <ul>
                      <li>
                        Adherir formalmente al Sistema de Identidad Digital.
                      </li>
                      <li>Elegir validación de datos, vigencia o biometría.</li>
                      <li>
                        Obtener credenciales y documentación del ambiente de
                        prueba.
                      </li>
                      <li>
                        Aprobar consentimiento, seguridad y tratamiento de datos
                        personales.
                      </li>
                    </ul>
                  </div>
                  <div className="risk-report-guided-actions">
                    <a
                      className="global-button global-button--ghost"
                      href={detail.fuentes_oficiales?.renaper_sid}
                      rel="noreferrer"
                      target="_blank"
                    >
                      Servicios SID <GlobalIcon name="external" size={14} />
                    </a>
                    <a
                      className="global-button global-button--ghost"
                      href={detail.fuentes_oficiales?.renaper_adhesion}
                      rel="noreferrer"
                      target="_blank"
                    >
                      Trámite de adhesión{" "}
                      <GlobalIcon name="external" size={14} />
                    </a>
                  </div>
                </section>
              </div>
            ) : null}

            {activeTab === "uif" ? (
              <div className="risk-report-uif">
                <div className="risk-report-legal-note">
                  <GlobalIcon name="shield" size={20} />
                  <span>
                    Esta segmentación implementa reglas internas explicables
                    basadas en debida diligencia. No consulta una “base de
                    aprobados/rechazados UIF” —esa API pública no existe— y no
                    genera un ROS automáticamente.
                  </span>
                </div>

                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Perfil transaccional</span>
                      <h3>Datos y propósito</h3>
                    </div>
                    <RiskBadge value={report.riesgo_uif} />
                  </header>
                  <div className="risk-report-form-grid is-three">
                    <label className="entity-field is-active">
                      <span>Actividad *</span>
                      <input
                        disabled={!canEvaluate}
                        onChange={(event) =>
                          setUifValue("actividad", event.target.value)
                        }
                        value={uif.actividad}
                      />
                    </label>
                    <label className="entity-field is-active">
                      <span>Monto solicitado (ARS) *</span>
                      <input
                        disabled={!canEvaluate}
                        min="0"
                        onChange={(event) =>
                          setUifValue("monto_solicitado", event.target.value)
                        }
                        type="number"
                        value={uif.monto_solicitado}
                      />
                    </label>
                    <label className="entity-field is-active">
                      <span>Ingresos mensuales (ARS)</span>
                      <input
                        disabled={!canEvaluate}
                        min="0"
                        onChange={(event) =>
                          setUifValue("ingresos_mensuales", event.target.value)
                        }
                        type="number"
                        value={uif.ingresos_mensuales}
                      />
                    </label>
                    <label className="entity-field is-active">
                      <span>Patrimonio estimado (ARS)</span>
                      <input
                        disabled={!canEvaluate}
                        min="0"
                        onChange={(event) =>
                          setUifValue("patrimonio_estimado", event.target.value)
                        }
                        type="number"
                        value={uif.patrimonio_estimado}
                      />
                    </label>
                    <label className="entity-field is-active is-span-2">
                      <span>Propósito de la relación / ayuda *</span>
                      <input
                        disabled={!canEvaluate}
                        onChange={(event) =>
                          setUifValue("proposito", event.target.value)
                        }
                        value={uif.proposito}
                      />
                    </label>
                    <label className="entity-field is-active is-span-3">
                      <span>Origen de fondos *</span>
                      <textarea
                        disabled={!canEvaluate}
                        onChange={(event) =>
                          setUifValue("origen_fondos", event.target.value)
                        }
                        rows={2}
                        value={uif.origen_fondos}
                      />
                    </label>
                  </div>
                </section>

                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Debida diligencia</span>
                      <h3>Controles y factores</h3>
                    </div>
                    <a
                      href={detail.fuentes_oficiales?.repet}
                      rel="noreferrer"
                      target="_blank"
                    >
                      Abrir RePET <GlobalIcon name="external" size={14} />
                    </a>
                  </header>
                  <div className="risk-report-form-grid is-three">
                    <label className="entity-field is-active">
                      <span>Condición PEP</span>
                      <select
                        disabled={!canEvaluate}
                        onChange={(event) =>
                          setUifValue("pep_estado", event.target.value)
                        }
                        value={uif.pep_estado}
                      >
                        <option value="NO_INFORMA">
                          Pendiente / no informa
                        </option>
                        <option value="NO">No PEP</option>
                        <option value="NACIONAL">PEP nacional</option>
                        <option value="EXTRANJERA">PEP extranjera</option>
                      </select>
                    </label>
                    <article className="risk-report-summary-card is-span-2">
                      <span>Resultado RePET automático</span>
                      <RiskBadge
                        value={repetSummary.resultado || "PENDIENTE"}
                      />
                      <small>
                        No puede editarse manualmente. Se obtiene de los JSON
                        oficiales y toda coincidencia potencial se escala.
                      </small>
                    </article>
                  </div>
                  <div className="risk-report-check-grid">
                    <BooleanField
                      checked={uif.identidad_verificada}
                      label="Identidad verificada"
                      onChange={(event) =>
                        setUifValue(
                          "identidad_verificada",
                          event.target.checked,
                        )
                      }
                    />
                    <BooleanField
                      checked={uif.documentacion_completa}
                      label="Documentación mínima completa"
                      onChange={(event) =>
                        setUifValue(
                          "documentacion_completa",
                          event.target.checked,
                        )
                      }
                    />
                    <BooleanField
                      checked={uif.origen_fondos_documentado}
                      label="Origen de fondos documentado"
                      onChange={(event) =>
                        setUifValue(
                          "origen_fondos_documentado",
                          event.target.checked,
                        )
                      }
                    />
                    <BooleanField
                      checked={uif.no_residente}
                      label="No residente"
                      onChange={(event) =>
                        setUifValue("no_residente", event.target.checked)
                      }
                    />
                    <BooleanField
                      checked={uif.jurisdiccion_riesgo}
                      label="Jurisdicción de riesgo"
                      onChange={(event) =>
                        setUifValue("jurisdiccion_riesgo", event.target.checked)
                      }
                    />
                    <BooleanField
                      checked={uif.efectivo_intensivo}
                      label="Actividad intensiva en efectivo"
                      onChange={(event) =>
                        setUifValue("efectivo_intensivo", event.target.checked)
                      }
                    />
                    <BooleanField
                      checked={uif.fondos_terceros}
                      label="Intervienen fondos de terceros"
                      onChange={(event) =>
                        setUifValue("fondos_terceros", event.target.checked)
                      }
                    />
                    <BooleanField
                      checked={uif.datos_contradictorios}
                      label="Datos contradictorios"
                      onChange={(event) =>
                        setUifValue(
                          "datos_contradictorios",
                          event.target.checked,
                        )
                      }
                    />
                    <BooleanField
                      checked={uif.comportamiento_inusual}
                      label="Comportamiento inusual previo"
                      onChange={(event) =>
                        setUifValue(
                          "comportamiento_inusual",
                          event.target.checked,
                        )
                      }
                    />
                  </div>
                  <label className="entity-field is-active risk-report-full-field">
                    <span>Observaciones del analista</span>
                    <textarea
                      disabled={!canEvaluate}
                      onChange={(event) =>
                        setUifValue("observaciones", event.target.value)
                      }
                      rows={3}
                      value={uif.observaciones}
                    />
                  </label>
                  {canEvaluate ? (
                    <button
                      className="global-button global-button--primary"
                      disabled={Boolean(busy)}
                      onClick={saveUif}
                      type="button"
                    >
                      <GlobalIcon
                        className={busy === "uif" ? "is-spinning" : ""}
                        name={busy === "uif" ? "loader" : "shield"}
                        size={16}
                      />
                      Calcular y guardar evaluación
                    </button>
                  ) : null}
                </section>

                {detail.evaluacion_uif ? (
                  <section className="risk-report-section">
                    <header>
                      <div>
                        <span>
                          Versión {detail.evaluacion_uif.version_reglas}
                        </span>
                        <h3>Resultado explicable</h3>
                      </div>
                      <RiskBadge value={detail.evaluacion_uif.nivel_riesgo} />
                    </header>
                    <div className="risk-report-alert-list">
                      {!detail.alertas?.length ? (
                        <div className="risk-report-empty is-compact">
                          <GlobalIcon name="check" size={22} />
                          <span>
                            No se generaron alertas con los datos actuales.
                          </span>
                        </div>
                      ) : (
                        detail.alertas.map((alert) => (
                          <article key={alert.id_alerta}>
                            <RiskBadge value={alert.severidad} />
                            <span>
                              <strong>
                                {alert.codigo} · {alert.descripcion}
                              </strong>
                              <small>{alert.accion_requerida}</small>
                            </span>
                          </article>
                        ))
                      )}
                    </div>
                    {detail.evaluacion_uif.documentacion_pendiente?.length ? (
                      <div className="risk-report-pending">
                        <strong>Documentación / controles pendientes</strong>
                        <ul>
                          {detail.evaluacion_uif.documentacion_pendiente.map(
                            (item) => (
                              <li key={item}>{item}</li>
                            ),
                          )}
                        </ul>
                      </div>
                    ) : null}
                  </section>
                ) : null}
              </div>
            ) : null}

            {activeTab === "decision" ? (
              <div className="risk-report-decision">
                <div className="risk-report-legal-note">
                  <GlobalIcon name="warning" size={20} />
                  <span>
                    El dictamen es una decisión interna y humana. Una alerta
                    crítica requiere un perfil de Cumplimiento; nunca equivale a
                    una aprobación o rechazo de la UIF.
                  </span>
                </div>
                <section className="risk-report-section">
                  <header>
                    <div>
                      <span>Decisión fundada</span>
                      <h3>Dictamen del informe</h3>
                    </div>
                    {detail.dictamen ? (
                      <RiskBadge value={detail.dictamen.resultado} />
                    ) : null}
                  </header>
                  <div className="risk-report-decision-context">
                    <SummaryCard
                      badge
                      label="Crédito"
                      value={report.riesgo_crediticio}
                    />
                    <SummaryCard
                      badge
                      label="LA/FT"
                      value={report.riesgo_uif}
                    />
                    <SummaryCard
                      badge
                      label="Documentación"
                      value={report.documentacion_estado}
                    />
                    <SummaryCard
                      badge
                      label="RePET"
                      value={repetSummary.resultado || "PENDIENTE"}
                    />
                  </div>
                  <div className="risk-report-form-grid">
                    <label className="entity-field is-active">
                      <span>Resultado</span>
                      <select
                        disabled={!canDecide}
                        onChange={(event) =>
                          setDecision((currentValue) => ({
                            ...currentValue,
                            resultado: event.target.value,
                          }))
                        }
                        value={decision.resultado}
                      >
                        <option value="RECOMENDADO">Recomendado</option>
                        <option value="CONDICIONADO">Condicionado</option>
                        <option value="REVISION">Revisión</option>
                        <option value="NO_RECOMENDADO">No recomendado</option>
                        <option value="NO_CONTINUAR_DD">
                          No continuar debida diligencia
                        </option>
                      </select>
                    </label>
                    <label className="entity-field is-active">
                      <span>Condiciones</span>
                      <textarea
                        disabled={!canDecide}
                        onChange={(event) =>
                          setDecision((currentValue) => ({
                            ...currentValue,
                            condiciones: event.target.value,
                          }))
                        }
                        rows={3}
                        value={decision.condiciones}
                      />
                    </label>
                    <label className="entity-field is-active is-span-2">
                      <span>Fundamento *</span>
                      <textarea
                        disabled={!canDecide}
                        onChange={(event) =>
                          setDecision((currentValue) => ({
                            ...currentValue,
                            fundamento: event.target.value,
                          }))
                        }
                        rows={4}
                        value={decision.fundamento}
                      />
                    </label>
                  </div>
                  {canDecide ? (
                    <button
                      className="global-button global-button--primary"
                      disabled={Boolean(busy) || !detail.evaluacion_uif}
                      onClick={saveDecision}
                      type="button"
                    >
                      <GlobalIcon
                        className={busy === "decision" ? "is-spinning" : ""}
                        name={busy === "decision" ? "loader" : "check"}
                        size={16}
                      />
                      Registrar dictamen humano
                    </button>
                  ) : (
                    <small className="risk-report-permission">
                      Tu perfil puede ver el dictamen, pero no emitirlo.
                    </small>
                  )}
                </section>

                {detail.historial_dictamenes?.length ? (
                  <section className="risk-report-section">
                    <header>
                      <div>
                        <span>Append-only</span>
                        <h3>Historial de dictámenes</h3>
                      </div>
                    </header>
                    <div className="risk-report-decision-history">
                      {detail.historial_dictamenes.map((item) => (
                        <article key={item.id_dictamen}>
                          <RiskBadge value={item.resultado} />
                          <span>
                            <strong>
                              {item.dictaminado_por_nombre ||
                                "Usuario autorizado"}{" "}
                              · {dateTime(item.dictaminado_en)}
                            </strong>
                            <small>
                              {item.fundamento || "Sin fundamento adicional"}
                            </small>
                          </span>
                        </article>
                      ))}
                    </div>
                  </section>
                ) : null}
              </div>
            ) : null}
          </div>
        </div>
      )}
    </CrudModal>
  );
}
