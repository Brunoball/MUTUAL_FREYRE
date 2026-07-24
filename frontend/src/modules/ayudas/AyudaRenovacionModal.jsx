import React, { useEffect, useState } from "react";
import CrudModal from "../../Global/components/CrudModal";
import GlobalIcon from "../../Global/components/GlobalIcon";
import {
  EntityFormPanel,
  EntityTabs,
} from "../../Global/components/TabbedForm";
import { renovarAyuda } from "./ayudas.api";
import {
  aidNumber,
  formatDate,
  formatMoney,
  localDateValue,
  numericValue,
} from "./ayudas.utils";

const initialState = (detail) => {
  const due = String(detail?.ayuda?.fecha_vencimiento || "");
  const today = localDateValue();
  return {
    fecha_renovacion: due && due > today ? due : today,
    plazo_dias: "30",
    gastos_administrativos: "0",
    otros_gastos: "0",
    recupero_gastos: "0",
    sellado: "0",
    seguro: "0",
    observaciones: "",
    confirmar_cobro_intereses: false,
  };
};

export default function AyudaRenovacionModal({
  open,
  detail,
  onClose,
  onRenewed,
}) {
  const [form, setForm] = useState(() => initialState(detail));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [activeTab, setActiveTab] = useState("conditions");
  const aid = detail?.ayuda || {};

  useEffect(() => {
    if (!open) return;
    setForm(initialState(detail));
    setError("");
    setActiveTab("conditions");
  }, [detail, open]);

  const update = (field, value) =>
    setForm((current) => ({ ...current, [field]: value }));

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const response = await renovarAyuda(aid.id_ayuda, {
        fecha_renovacion: form.fecha_renovacion,
        plazo_dias: Number(form.plazo_dias),
        gastos_administrativos: numericValue(form.gastos_administrativos),
        otros_gastos: numericValue(form.otros_gastos),
        recupero_gastos: numericValue(form.recupero_gastos),
        sellado: numericValue(form.sellado),
        seguro: numericValue(form.seguro),
        observaciones: form.observaciones,
        confirmar_cobro_intereses: form.confirmar_cobro_intereses,
      });
      onRenewed?.(response);
    } catch (submitError) {
      setError(submitError?.message || "No se pudo renovar la ayuda.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <CrudModal
      modalClassName="ayuda-renovacion-modal"
      onClose={onClose}
      onSubmit={submit}
      open={open}
      saving={busy}
      savingLabel="Renovando..."
      submitDisabled={!form.confirmar_cobro_intereses}
      submitLabel="Confirmar renovación"
      subtitle="La renovación conserva el capital, registra el cobro de los intereses anteriores y no acredita nuevamente el capital."
      title={`Renovar ayuda B N° ${aidNumber(aid.numero_ayuda)}`}
      wide
    >
      {error ? (
        <div className="ayuda-alert is-error">
          <GlobalIcon name="error" size={18} />
          <span>{error}</span>
        </div>
      ) : null}

      <div className="ayuda-alert is-warning">
        <GlobalIcon name="warning" size={18} />
        <span>
          Antes de continuar, verificá que el socio haya pagado los intereses
          del período vencido. Esta pantalla no registra un cobro de caja: solo
          deja asentada la confirmación operativa de la renovación.
        </span>
      </div>

      <div className="ayuda-summary-grid ayuda-summary-grid--compact">
        <div>
          <span>Socio</span>
          <strong>{aid.socio_nombre || "—"}</strong>
        </div>
        <div>
          <span>Capital que se renueva</span>
          <strong>{formatMoney(aid.capital_original, "ARS")}</strong>
        </div>
        <div>
          <span>Vencimiento anterior</span>
          <strong>{formatDate(aid.fecha_vencimiento)}</strong>
        </div>
        <div>
          <span>Intereses a cobrar</span>
          <strong>{formatMoney(aid.devengamiento_total, "ARS")}</strong>
        </div>
      </div>

      <div className="entity-form ayuda-modal__form ayuda-renewal-tabs">
        <EntityTabs
          ariaLabel="Secciones de la renovación"
          idPrefix="ayuda-renovacion-tab"
          onChange={setActiveTab}
          tabs={[
            { value: "conditions", label: "Nuevas condiciones" },
            { value: "expenses", label: "Gastos del período" },
          ]}
          value={activeTab}
        />
        <div className="ayuda-modal__content">
          {activeTab === "conditions" ? (
            <EntityFormPanel
              eyebrow="Nuevo período"
              idPrefix="ayuda-renovacion-tab"
              tabValue="conditions"
              title="Condiciones de la renovación"
            >
              <div className="ayuda-form-section">
                <div className="ayuda-form-section__title">
                  <strong>Nuevas condiciones</strong>
                  <span>
                    La tasa vigente se toma automáticamente según la fecha de
                    renovación.
                  </span>
                </div>
                <div className="ayuda-form-grid ayuda-form-grid--4">
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      Fecha de renovación <b>*</b>
                    </span>
                    <input
                      max={localDateValue()}
                      min={aid.fecha_vencimiento || undefined}
                      onChange={(event) =>
                        update("fecha_renovacion", event.target.value)
                      }
                      type="date"
                      value={form.fecha_renovacion}
                    />
                  </label>
                  <label className="entity-field ayuda-field is-active">
                    <span>
                      Nuevo plazo <b>*</b>
                    </span>
                    <select
                      onChange={(event) =>
                        update("plazo_dias", event.target.value)
                      }
                      value={form.plazo_dias}
                    >
                      <option value="30">30 DÍAS</option>
                      <option value="60">60 DÍAS</option>
                    </select>
                  </label>
                  <label
                    className={`entity-field ayuda-field is-span-2 ${form.observaciones ? "is-active" : ""}`}
                  >
                    <span>Observaciones</span>
                    <input
                      onChange={(event) =>
                        update("observaciones", event.target.value)
                      }
                      placeholder=" "
                      value={form.observaciones}
                    />
                  </label>
                </div>
              </div>
            </EntityFormPanel>
          ) : null}

          {activeTab === "expenses" ? (
            <EntityFormPanel
              eyebrow="Importes adicionales"
              idPrefix="ayuda-renovacion-tab"
              tabValue="expenses"
              title="Gastos del nuevo período"
            >
              <div className="ayuda-form-section">
                <div className="ayuda-form-section__title">
                  <strong>Gastos del nuevo período</strong>
                </div>
                <div className="ayuda-form-grid ayuda-form-grid--5">
                  {[
                    ["gastos_administrativos", "Gastos administrativos"],
                    ["otros_gastos", "Otros gastos"],
                    ["recupero_gastos", "Recupero de gastos"],
                    ["sellado", "Sellado"],
                    ["seguro", "Seguro"],
                  ].map(([key, label]) => (
                    <label
                      className="entity-field ayuda-field is-active"
                      key={key}
                    >
                      <span>{label}</span>
                      <input
                        min="0"
                        onChange={(event) => update(key, event.target.value)}
                        step="0.01"
                        type="number"
                        value={form[key]}
                      />
                    </label>
                  ))}
                </div>
              </div>
            </EntityFormPanel>
          ) : null}
        </div>
      </div>

      <label className="ayuda-confirm-box">
        <input
          checked={form.confirmar_cobro_intereses}
          onChange={(event) =>
            update("confirmar_cobro_intereses", event.target.checked)
          }
          type="checkbox"
        />
        <span>
          <strong>
            Confirmo que los intereses del período anterior fueron cobrados.
          </strong>
          <small>
            Esta confirmación es obligatoria para cerrar la ayuda anterior como
            renovada y generar la nueva.
          </small>
        </span>
      </label>
    </CrudModal>
  );
}
