import React, { useEffect, useState } from "react";
import CrudModal from "../../Global/components/CrudModal";
import GlobalIcon from "../../Global/components/GlobalIcon";
import { anularAyuda } from "./ayudas.api";
import { aidNumber, formatMoney, localDateValue } from "./ayudas.utils";

export default function AyudaAnulacionModal({
  open,
  detail,
  onClose,
  onAnnulled,
}) {
  const [reason, setReason] = useState("");
  const [date, setDate] = useState(() => localDateValue());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const aid = detail?.ayuda || {};
  const currentBalance = Number(aid.saldo_caja_ahorro_comun || 0);
  const credited = Number(aid.importe_acreditado_ars || 0);
  const canReverse = credited <= 0 || currentBalance + 0.00001 >= credited;

  useEffect(() => {
    if (!open) return;
    setReason("");
    setDate(localDateValue());
    setError("");
  }, [open]);

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const response = await anularAyuda(aid.id_ayuda, {
        motivo: reason,
        fecha: date,
      });
      onAnnulled?.(response);
    } catch (submitError) {
      setError(submitError?.message || "No se pudo anular la liquidación.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <CrudModal
      danger
      modalClassName="ayuda-anulacion-modal"
      onClose={onClose}
      onSubmit={submit}
      open={open}
      saving={busy}
      savingLabel="Anulando..."
      submitDisabled={reason.trim().length < 10 || !date || !canReverse}
      submitLabel={
        credited > 0 ? "Anular y revertir acreditación" : "Anular liquidación"
      }
      subtitle={
        credited > 0
          ? "La anulación conserva el historial, anula el plan y revierte la acreditación de la caja de ahorro común."
          : "La anulación conserva el historial y anula el plan. Esta renovación no generó un nuevo desembolso de capital."
      }
      title={`Anular ayuda N° ${aidNumber(aid.numero_ayuda)}`}
    >
      {error ? (
        <div className="ayuda-alert is-error">
          <GlobalIcon name="error" size={18} />
          <span>{error}</span>
        </div>
      ) : null}
      <div className={`ayuda-alert ${canReverse ? "is-warning" : "is-error"}`}>
        <GlobalIcon name={canReverse ? "warning" : "error"} size={18} />
        <span>
          {credited <= 0
            ? "Esta liquidación no acreditó nuevamente el capital, por lo que no hay un movimiento de caja que revertir. La operación no elimina registros."
            : canReverse
              ? `Se descontarán ${formatMoney(credited, "ARS")} de la caja común del socio. La operación no elimina registros.`
              : `No se puede anular desde este módulo: la caja común tiene ${formatMoney(currentBalance, "ARS")} y la liquidación acreditó ${formatMoney(credited, "ARS")}. Parte de los fondos ya fue retirada o transferida.`}
        </span>
      </div>
      <div className="ayuda-form-grid ayuda-form-grid--2">
        <label className="entity-field ayuda-field is-active">
          <span>
            Fecha de anulación <b>*</b>
          </span>
          <input
            max={localDateValue()}
            min={aid.fecha_liquidacion || undefined}
            onChange={(event) => setDate(event.target.value)}
            type="date"
            value={date}
          />
        </label>
        <label className="entity-field ayuda-field is-active is-textarea">
          <span>
            Motivo <b>*</b>
          </span>
          <textarea
            maxLength="500"
            onChange={(event) => setReason(event.target.value)}
            placeholder=" "
            rows="4"
            value={reason}
          />
        </label>
      </div>
    </CrudModal>
  );
}
