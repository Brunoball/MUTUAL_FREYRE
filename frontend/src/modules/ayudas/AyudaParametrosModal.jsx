import React, { useCallback, useEffect, useState } from "react";
import CrudModal from "../../Global/components/CrudModal";
import GlobalIcon from "../../Global/components/GlobalIcon";
import {
  EntityFormPanel,
  EntityTabs,
} from "../../Global/components/TabbedForm";
import {
  consultarCotizacionBancoNacion,
  getAyudasParametros,
  guardarCotizacionDolar,
  guardarTasaAyuda,
} from "./ayudas.api";
import {
  formatDate,
  formatMoney,
  formatRate,
  localDateValue,
  numericValue,
} from "./ayudas.utils";

const GROUPS = [
  { value: "A", label: "A · COMPRA DE CHEQUES" },
  { value: "B", label: "B · AYUDA RENOVABLE" },
  { value: "EJ", label: "E / J · TASA BASE COMPARTIDA" },
  { value: "I", label: "I · AYUDA EN DÓLARES" },
];

const initialRate = () => ({
  grupo_tasa: "A",
  vigencia_desde: localDateValue(),
  tna: "",
  base_dias: "365",
  observaciones: "",
});

const initialQuote = () => ({
  fecha_referencia: localDateValue(),
  valor_promedio: "",
  fuente: "BANCO NACIÓN - PROMEDIO INFORMADO POR LA MUTUAL",
  observaciones: "",
});

export default function AyudaParametrosModal({ open, onClose, onSaved }) {
  const [data, setData] = useState({ tasas: [], cotizaciones: [] });
  const [rate, setRate] = useState(() => initialRate());
  const [quote, setQuote] = useState(() => initialQuote());
  const [bnaQuote, setBnaQuote] = useState(null);
  const [loading, setLoading] = useState(false);
  const [consulting, setConsulting] = useState(false);
  const [saving, setSaving] = useState("");
  const [feedback, setFeedback] = useState(null);
  const [activeTab, setActiveTab] = useState("rates");

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await getAyudasParametros();
      setData({
        tasas: Array.isArray(response?.tasas) ? response.tasas : [],
        cotizaciones: Array.isArray(response?.cotizaciones)
          ? response.cotizaciones
          : [],
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudieron cargar los parámetros.",
      });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    setFeedback(null);
    setBnaQuote(null);
    setActiveTab("rates");
    load();
  }, [load, open]);

  const saveRate = async () => {
    setSaving("rate");
    setFeedback(null);
    try {
      await guardarTasaAyuda({
        ...rate,
        tna: numericValue(rate.tna),
        base_dias: Number(rate.base_dias),
      });
      setRate(initialRate());
      await load();
      onSaved?.();
      setFeedback({
        type: "success",
        message: "La nueva vigencia de tasa quedó registrada.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo guardar la tasa.",
      });
    } finally {
      setSaving("");
    }
  };

  const consultBnaQuote = async () => {
    setConsulting(true);
    setFeedback(null);
    try {
      const response = await consultarCotizacionBancoNacion();
      setBnaQuote(response);
      setQuote((current) => ({
        ...current,
        fecha_referencia: response?.fecha_cotizacion || localDateValue(),
        valor_promedio: String(response?.promedio ?? ""),
        fuente:
          response?.fuente ||
          "BANCO NACIÓN - COTIZACIÓN BILLETE (PROMEDIO COMPRA/VENTA)",
      }));
      setFeedback({
        type: response?.es_respaldo ? "warning" : "success",
        message: response?.es_respaldo
          ? "Banco Nación no respondió y se cargó una referencia del dólar oficial como respaldo. Revisala y modificala si hace falta antes de guardarla."
          : "Se obtuvo la cotización billete del Banco Nación. El promedio sugerido quedó cargado, pero podés modificarlo antes de guardarlo.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message:
          error?.message ||
          "No se pudo consultar la cotización del Banco Nación.",
      });
    } finally {
      setConsulting(false);
    }
  };

  const saveQuote = async () => {
    setSaving("quote");
    setFeedback(null);
    try {
      await guardarCotizacionDolar({
        ...quote,
        valor_promedio: numericValue(quote.valor_promedio),
      });
      setQuote(initialQuote());
      await load();
      onSaved?.();
      setFeedback({
        type: "success",
        message: "La cotización mensual quedó actualizada.",
      });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo guardar la cotización.",
      });
    } finally {
      setSaving("");
    }
  };

  return (
    <CrudModal
      hideSubmit
      modalClassName="ayuda-parameters-modal"
      onClose={onClose}
      open={open}
      saving={Boolean(saving)}
      subtitle="Las tasas se versionan por fecha y la cotización del dólar se actualiza por mes. Las liquidaciones históricas no se recalculan."
      title="Tasas y cotización del dólar"
      wide
    >
      {feedback ? (
        <div className={`ayuda-alert is-${feedback.type}`}>
          <GlobalIcon
            name={
              feedback.type === "success"
                ? "check"
                : feedback.type === "warning"
                  ? "warning"
                  : "error"
            }
            size={18}
          />
          <span>{feedback.message}</span>
        </div>
      ) : null}

      <div className="entity-form ayuda-modal__form ayuda-parameters-tabs">
        <EntityTabs
          ariaLabel="Secciones de tasas y cotización"
          idPrefix="ayuda-parametros-tab"
          onChange={setActiveTab}
          tabs={[
            { value: "rates", label: "Tasas" },
            { value: "quote", label: "Cotización del dólar" },
            {
              value: "history",
              label: "Historial",
              badge: data.tasas.length + data.cotizaciones.length,
            },
          ]}
          value={activeTab}
        />
        <div className="ayuda-modal__content">
          {activeTab === "rates" ? (
            <EntityFormPanel
              eyebrow="Nueva vigencia"
              idPrefix="ayuda-parametros-tab"
              tabValue="rates"
              title="Tasas de ayudas"
            >
              <section className="ayuda-form-section">
                <div className="ayuda-form-section__title">
                  <strong>Nueva tasa</strong>
                  <span>
                    E y J comparten la misma tasa base, tal como indica la
                    operatoria informada.
                  </span>
                </div>
                <div className="ayuda-form-grid ayuda-form-grid--2">
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      Grupo de ayuda <b>*</b>
                    </span>
                    <select
                      value={rate.grupo_tasa}
                      onChange={(event) =>
                        setRate((current) => ({
                          ...current,
                          grupo_tasa: event.target.value,
                        }))
                      }
                    >
                      {GROUPS.map((item) => (
                        <option key={item.value} value={item.value}>
                          {item.label}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      Vigencia desde <b>*</b>
                    </span>
                    <input
                      type="date"
                      value={rate.vigencia_desde}
                      onChange={(event) =>
                        setRate((current) => ({
                          ...current,
                          vigencia_desde: event.target.value,
                        }))
                      }
                    />
                  </label>
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      TNA (%) <b>*</b>
                    </span>
                    <input
                      min="0"
                      max="500"
                      step="0.0001"
                      type="number"
                      value={rate.tna}
                      onChange={(event) =>
                        setRate((current) => ({
                          ...current,
                          tna: event.target.value,
                        }))
                      }
                    />
                  </label>
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      Base anual <b>*</b>
                    </span>
                    <select
                      value={rate.base_dias}
                      onChange={(event) =>
                        setRate((current) => ({
                          ...current,
                          base_dias: event.target.value,
                        }))
                      }
                    >
                      <option value="365">365 DÍAS</option>
                      <option value="360">360 DÍAS</option>
                    </select>
                  </label>
                  <label
                    className={`entity-field ayuda-field ${rate.observaciones ? "is-active" : ""}`}
                  >
                    <span>Observaciones</span>
                    <input
                      maxLength="500"
                      value={rate.observaciones}
                      onChange={(event) =>
                        setRate((current) => ({
                          ...current,
                          observaciones: event.target.value,
                        }))
                      }
                    />
                  </label>
                </div>
                <button
                  className="global-button global-button--primary ayuda-parameter-save"
                  disabled={saving || !rate.tna || !rate.vigencia_desde}
                  onClick={saveRate}
                  type="button"
                >
                  {saving === "rate" ? (
                    <GlobalIcon
                      className="is-spinning"
                      name="loader"
                      size={16}
                    />
                  ) : (
                    <GlobalIcon name="plus" size={16} />
                  )}
                  Registrar vigencia
                </button>
              </section>
            </EntityFormPanel>
          ) : null}

          {activeTab === "quote" ? (
            <EntityFormPanel
              eyebrow="Referencia mensual"
              idPrefix="ayuda-parametros-tab"
              tabValue="quote"
              title="Cotización del dólar"
            >
              <section className="ayuda-form-section">
                <div className="ayuda-form-section__title ayuda-form-section__title--actions">
                  <div>
                    <strong>Cotización mensual del dólar</strong>
                    <span>
                      Consultá el valor billete actual del Banco Nación. El
                      promedio se completa como sugerencia y sigue siendo
                      editable.
                    </span>
                  </div>
                  <button
                    className="global-button global-button--secondary ayuda-bna-consult"
                    disabled={Boolean(saving) || consulting}
                    onClick={consultBnaQuote}
                    type="button"
                  >
                    {consulting ? (
                      <GlobalIcon
                        className="is-spinning"
                        name="loader"
                        size={16}
                      />
                    ) : (
                      <GlobalIcon name="refresh" size={16} />
                    )}
                    {consulting ? "Consultando..." : "Consultar Banco Nación"}
                  </button>
                </div>

                {bnaQuote ? (
                  <div className="ayuda-bna-quote">
                    <div>
                      <span>Compra</span>
                      <strong>{formatMoney(bnaQuote.compra, "ARS")}</strong>
                    </div>
                    <div>
                      <span>Venta</span>
                      <strong>{formatMoney(bnaQuote.venta, "ARS")}</strong>
                    </div>
                    <div className="is-highlighted">
                      <span>Promedio sugerido</span>
                      <strong>{formatMoney(bnaQuote.promedio, "ARS")}</strong>
                    </div>
                    <small>
                      {bnaQuote.es_respaldo
                        ? "Referencia del dólar oficial (respaldo)"
                        : "Banco Nación · Cotización billete"}
                      {bnaQuote.fecha_cotizacion
                        ? ` · ${formatDate(bnaQuote.fecha_cotizacion)}`
                        : ""}
                      {bnaQuote.hora_actualizacion
                        ? ` · ${bnaQuote.hora_actualizacion} hs`
                        : ""}
                    </small>
                  </div>
                ) : null}

                <div className="ayuda-form-grid ayuda-form-grid--2">
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      Fecha de referencia <b>*</b>
                    </span>
                    <input
                      type="date"
                      value={quote.fecha_referencia}
                      onChange={(event) =>
                        setQuote((current) => ({
                          ...current,
                          fecha_referencia: event.target.value,
                        }))
                      }
                    />
                  </label>
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      Valor promedio (ARS) <b>*</b>
                    </span>
                    <input
                      min="0"
                      step="0.000001"
                      type="number"
                      value={quote.valor_promedio}
                      onChange={(event) =>
                        setQuote((current) => ({
                          ...current,
                          valor_promedio: event.target.value,
                        }))
                      }
                    />
                  </label>
                  <label className="entity-field ayuda-field is-span-2 is-active">
                    <span>Fuente</span>
                    <input
                      maxLength="180"
                      value={quote.fuente}
                      onChange={(event) =>
                        setQuote((current) => ({
                          ...current,
                          fuente: event.target.value,
                        }))
                      }
                    />
                  </label>
                  <label
                    className={`entity-field ayuda-field is-span-2 ${quote.observaciones ? "is-active" : ""}`}
                  >
                    <span>Observaciones</span>
                    <input
                      maxLength="500"
                      value={quote.observaciones}
                      onChange={(event) =>
                        setQuote((current) => ({
                          ...current,
                          observaciones: event.target.value,
                        }))
                      }
                    />
                  </label>
                </div>
                <button
                  className="global-button global-button--primary ayuda-parameter-save"
                  disabled={
                    Boolean(saving) ||
                    consulting ||
                    !quote.valor_promedio ||
                    !quote.fecha_referencia
                  }
                  onClick={saveQuote}
                  type="button"
                >
                  {saving === "quote" ? (
                    <GlobalIcon
                      className="is-spinning"
                      name="loader"
                      size={16}
                    />
                  ) : (
                    <GlobalIcon name="refresh" size={16} />
                  )}
                  Guardar cotización del mes
                </button>
              </section>
            </EntityFormPanel>
          ) : null}

          {activeTab === "history" ? (
            <EntityFormPanel
              eyebrow="Vigencias registradas"
              idPrefix="ayuda-parametros-tab"
              tabValue="history"
              title="Historial de parámetros"
            >
              <div className="ayuda-parameter-history">
                <section className="ayuda-detail-section">
                  <header>
                    <strong>Historial de tasas</strong>
                    <span>{data.tasas.length} vigencias</span>
                  </header>
                  <div className="ayuda-history-list">
                    {data.tasas.map((item) => (
                      <article
                        className="ayuda-history-card"
                        key={item.id_tasa}
                      >
                        <header>
                          <div>
                            <span>Grupo de ayuda</span>
                            <strong>Grupo {item.grupo_tasa}</strong>
                          </div>
                          <span className="ayuda-history-card__value">
                            {formatRate(item.tna)}
                          </span>
                        </header>
                        <div className="ayuda-history-card__meta">
                          <div>
                            <span>Vigencia desde</span>
                            <strong>{formatDate(item.vigencia_desde)}</strong>
                          </div>
                          <div>
                            <span>Base anual</span>
                            <strong>{item.base_dias || 365} días</strong>
                          </div>
                        </div>
                        {item.observaciones ? (
                          <p>{item.observaciones}</p>
                        ) : null}
                      </article>
                    ))}
                    {!loading && !data.tasas.length ? (
                      <div className="ayuda-history-empty">
                        <GlobalIcon name="inbox" size={22} />
                        <span>Todavía no se registraron tasas.</span>
                      </div>
                    ) : null}
                  </div>
                </section>

                <section className="ayuda-detail-section">
                  <header>
                    <strong>Historial de cotizaciones</strong>
                    <span>{data.cotizaciones.length} registros</span>
                  </header>
                  <div className="ayuda-history-list">
                    {data.cotizaciones.map((item) => (
                      <article
                        className="ayuda-history-card"
                        key={item.id_cotizacion}
                      >
                        <header>
                          <div>
                            <span>Período</span>
                            <strong>{item.periodo}</strong>
                          </div>
                          <span className="ayuda-history-card__value">
                            {formatMoney(item.valor_promedio, "ARS")}
                          </span>
                        </header>
                        <div className="ayuda-history-card__meta">
                          <div>
                            <span>Fecha de referencia</span>
                            <strong>{formatDate(item.fecha_referencia)}</strong>
                          </div>
                        </div>
                        <p>
                          <b>Fuente:</b> {item.fuente || "Sin informar"}
                        </p>
                      </article>
                    ))}
                    {!loading && !data.cotizaciones.length ? (
                      <div className="ayuda-history-empty">
                        <GlobalIcon name="inbox" size={22} />
                        <span>Todavía no se registraron cotizaciones.</span>
                      </div>
                    ) : null}
                  </div>
                </section>
              </div>
            </EntityFormPanel>
          ) : null}
        </div>
      </div>
    </CrudModal>
  );
}
