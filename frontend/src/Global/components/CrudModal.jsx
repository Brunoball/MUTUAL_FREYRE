import React, { useEffect } from "react";
import { createPortal } from "react-dom";
import GlobalIcon from "./GlobalIcon";

export default function CrudModal({
  open,
  title,
  subtitle,
  children,
  onClose,
  onSubmit,
  saving = false,
  savingLabel = "Guardando...",
  submitLabel = "Guardar",
  danger = false,
  wide = false,
  hideSubmit = false,
  submitDisabled = false,
  hideCancel = false,
  cancelLabel = "Cancelar",
  footerStart = null,
  modalClassName = "",
}) {
  useEffect(() => {
    if (!open) return undefined;

    const previousOverflow = document.body.style.overflow;
    const handleKeyDown = (event) => {
      if (event.key === "Escape" && !saving) onClose?.();
    };

    document.body.style.overflow = "hidden";
    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [open, onClose, saving]);

  if (!open) return null;

  return createPortal(
    <div
      className="entity-modal-overlay"
      onMouseDown={() => !saving && onClose?.()}
      role="presentation"
    >
      <section
        aria-labelledby="entity-modal-title"
        aria-modal="true"
        className={`entity-modal ${wide ? "entity-modal--wide" : ""} ${modalClassName}`.trim()}
        onMouseDown={(event) => event.stopPropagation()}
        role="dialog"
      >
        <header className="entity-modal__header">
          <div>
            <h2 id="entity-modal-title">{title}</h2>
            {subtitle ? <p>{subtitle}</p> : null}
          </div>
          <button
            aria-label="Cerrar"
            className="entity-modal__close"
            disabled={saving}
            onClick={onClose}
            type="button"
          >
            <GlobalIcon name="close" size={18} />
          </button>
        </header>

        <form onSubmit={onSubmit}>
          <div className="entity-modal__body">{children}</div>
          {footerStart || !hideCancel || !hideSubmit ? (
            <footer className="entity-modal__footer">
              {footerStart ? (
                <div className="entity-modal__footer-start">{footerStart}</div>
              ) : null}
              {!hideCancel ? (
                <button
                  className="global-button global-button--ghost"
                  disabled={saving}
                  onClick={onClose}
                  type="button"
                >
                  {cancelLabel}
                </button>
              ) : null}
              {!hideSubmit ? (
                <button
                  className={`global-button ${danger ? "global-button--danger" : "global-button--primary"}`}
                  disabled={saving || submitDisabled}
                  type="submit"
                >
                  {saving ? (
                    <GlobalIcon
                      className="is-spinning"
                      name="loader"
                      size={16}
                    />
                  ) : null}
                  {saving ? savingLabel : submitLabel}
                </button>
              ) : null}
            </footer>
          ) : null}
        </form>
      </section>
    </div>,
    document.body,
  );
}
