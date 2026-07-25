import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";
import GlobalIcon from "../components/GlobalIcon";
import Toast from "../Toast";
import "../Global_css/Global_ModalEliminar.css";

const OPERATION_CONFIG = {
  eliminar: {
    icon: "trash",
    tone: "danger",
    title: "Eliminar registro",
    message: "¿Seguro que querés eliminar este registro definitivamente?",
    warning: "Esta acción no se puede deshacer.",
    confirmLabel: "Eliminar",
    loadingLabel: "Eliminando...",
    loadingMessage: "Eliminando registro…",
    successMessage: "Registro eliminado correctamente.",
    errorMessage: "No se pudo eliminar el registro.",
  },
  baja: {
    icon: "disable",
    tone: "warning",
    title: "Dar de baja registro",
    message:
      "El registro dejará de figurar como activo, pero se conservará en dados de baja.",
    warning: "",
    confirmLabel: "Dar de baja",
    loadingLabel: "Procesando...",
    loadingMessage: "Dando de baja…",
    successMessage: "Registro dado de baja correctamente.",
    errorMessage: "No se pudo dar de baja el registro.",
  },
  alta: {
    icon: "enable",
    tone: "success",
    title: "Dar de alta registro",
    message: "El registro volverá a figurar como activo.",
    warning: "",
    confirmLabel: "Dar de alta",
    loadingLabel: "Procesando...",
    loadingMessage: "Dando de alta…",
    successMessage: "Registro dado de alta correctamente.",
    errorMessage: "No se pudo dar de alta el registro.",
  },
  advertencia: {
    icon: "warning",
    tone: "warning",
    title: "Confirmar acción",
    message: "¿Seguro que querés continuar?",
    warning: "",
    confirmLabel: "Confirmar",
    loadingLabel: "Procesando...",
    loadingMessage: "Procesando…",
    successMessage: "Operación realizada correctamente.",
    errorMessage: "No se pudo completar la operación.",
  },
};

const SCROLL_LOCK_PROPERTIES = [
  "overflow",
  "overflow-x",
  "overflow-y",
  "overscroll-behavior",
];
let scrollLockCount = 0;
const lockedElements = new Map();

const safeText = (value) => String(value ?? "").trim() || "—";
const toUppercase = (value) =>
  String(value ?? "").toLocaleUpperCase("es-AR");

function normalizeDetails(details = []) {
  if (!Array.isArray(details)) return [];

  return details
    .filter((item) => item && typeof item === "object")
    .map((item, index) => ({
      key: `${index}-${item.label ?? "detalle"}`,
      label: safeText(item.label),
      value: safeText(item.value),
    }));
}

function lockBackgroundScroll() {
  if (typeof document === "undefined" || typeof window === "undefined") {
    return () => {};
  }

  scrollLockCount += 1;
  if (scrollLockCount === 1) {
    const elements = new Set([
      document.documentElement,
      document.body,
      ...document.body.querySelectorAll("*"),
    ]);

    elements.forEach((element) => {
      if (!(element instanceof HTMLElement) || element.closest(".gdel-overlay")) {
        return;
      }

      const styles = window.getComputedStyle(element);
      const scrollable =
        element === document.documentElement ||
        element === document.body ||
        /(auto|scroll|overlay)/.test(
          `${styles.overflow} ${styles.overflowX} ${styles.overflowY}`,
        );

      if (!scrollable) return;

      const originals = {};
      SCROLL_LOCK_PROPERTIES.forEach((property) => {
        originals[property] = {
          priority: element.style.getPropertyPriority(property),
          value: element.style.getPropertyValue(property),
        };
      });
      lockedElements.set(element, originals);

      element.style.setProperty("overflow", "hidden", "important");
      element.style.setProperty("overflow-x", "hidden", "important");
      element.style.setProperty("overflow-y", "hidden", "important");
      element.style.setProperty("overscroll-behavior", "none", "important");
    });
  }

  let unlocked = false;
  return () => {
    if (unlocked) return;
    unlocked = true;
    scrollLockCount = Math.max(0, scrollLockCount - 1);
    if (scrollLockCount !== 0) return;

    lockedElements.forEach((originals, element) => {
      if (!(element instanceof HTMLElement)) return;

      SCROLL_LOCK_PROPERTIES.forEach((property) => {
        const original = originals[property];
        if (original.value) {
          element.style.setProperty(
            property,
            original.value,
            original.priority,
          );
        } else {
          element.style.removeProperty(property);
        }
      });
    });
    lockedElements.clear();
  };
}

export default function ModalEliminarGlobal({
  open,
  operacion = "eliminar",
  row = null,
  loading = false,
  onClose,
  onConfirm,
  onBeforeConfirm,
  onToast,
  title,
  message,
  warning,
  confirmLabel,
  cancelLabel = "Cancelar",
  loadingLabel,
  loadingMessage,
  successMessage,
  errorMessage,
  tone,
  icon,
  modalClassName = "",
  details = null,
  extraContent = null,
  hideDefaultCard = false,
  showReason = false,
  reasonLabel = "Motivo u observación",
  reasonPlaceholder = "Escribí una observación opcional...",
  reasonRequired = false,
  initialReason = "",
  closeOnSuccess = true,
  confirmDisabled = false,
}) {
  const cancelRef = useRef(null);
  const reasonRef = useRef(null);
  const [internalLoading, setInternalLoading] = useState(false);
  const [reason, setReason] = useState(toUppercase(initialReason));
  const [localToast, setLocalToast] = useState(null);

  useEffect(() => {
    if (!open) return undefined;
    return lockBackgroundScroll();
  }, [open]);

  const config = OPERATION_CONFIG[operacion] || OPERATION_CONFIG.advertencia;
  const isLoading = loading || internalLoading;
  const resolvedTone = tone || config.tone;
  const resolvedIcon = icon || config.icon;
  const resolvedTitle = title || config.title;
  const resolvedMessage = message || config.message;
  const resolvedWarning = warning ?? config.warning;
  const resolvedConfirmLabel = confirmLabel || config.confirmLabel;
  const resolvedLoadingLabel = loadingLabel || config.loadingLabel;
  const resolvedLoadingMessage = loadingMessage || config.loadingMessage;
  const resolvedSuccessMessage = successMessage || config.successMessage;
  const resolvedErrorMessage = errorMessage || config.errorMessage;

  useEffect(() => {
    if (!open) return;
    setReason(toUppercase(initialReason));
    setLocalToast(null);
  }, [initialReason, open]);

  const showToast = useCallback(
    (type, toastMessage, duration = 2800) => {
      if (!toastMessage) return;

      if (typeof onToast === "function") {
        onToast(type, toastMessage, duration);
        return;
      }

      setLocalToast({
        duration,
        id: Date.now(),
        message: toastMessage,
        type,
      });
    },
    [onToast],
  );

  const close = useCallback(() => {
    if (isLoading) return;
    onClose?.();
  }, [isLoading, onClose]);

  const resolvedDetails = useMemo(() => {
    const customDetails = normalizeDetails(details);
    if (customDetails.length > 0) return customDetails;

    return normalizeDetails([
      {
        label: "ID",
        value:
          row?.id ??
          row?.id_persona ??
          row?.id_docente ??
          row?.idMovimiento ??
          row?.id_movimiento,
      },
      {
        label: "Nombre",
        value:
          row?.nombre_exhibicion ??
          row?.nombre ??
          row?.docente ??
          row?.descripcion ??
          row?.concepto,
      },
      {
        label: "Estado",
        value:
          row?.estado ??
          row?.activo ??
          row?.cargo ??
          row?.tipo ??
          row?.tipo_movimiento,
      },
    ]);
  }, [details, row]);

  const handleConfirm = useCallback(async () => {
    if (isLoading || confirmDisabled || typeof onConfirm !== "function") return;

    const cleanReason = reason.trim();
    if (showReason && reasonRequired && !cleanReason) {
      showToast("error", "Tenés que completar el motivo para continuar.", 4200);
      return;
    }

    if (typeof onBeforeConfirm === "function") {
      const shouldContinue = onBeforeConfirm({
        motivo: cleanReason,
        operacion,
        reason: cleanReason,
        row,
      });
      if (shouldContinue === false) return;
    }

    setLocalToast(null);
    setInternalLoading(true);
    showToast("cargando", resolvedLoadingMessage, 12000);

    let closeAtEnd = false;
    try {
      const result = await onConfirm({
        motivo: cleanReason,
        operacion,
        reason: cleanReason,
        row,
      });

      if (result?.ok === false) {
        throw new Error(
          result.mensaje || result.message || resolvedErrorMessage,
        );
      }

      showToast("exito", resolvedSuccessMessage, 2800);
      closeAtEnd = closeOnSuccess;
    } catch (error) {
      showToast("error", error?.message || resolvedErrorMessage, 4200);
    } finally {
      setInternalLoading(false);
      if (closeAtEnd) onClose?.();
    }
  }, [
    closeOnSuccess,
    confirmDisabled,
    isLoading,
    onBeforeConfirm,
    onClose,
    onConfirm,
    operacion,
    reason,
    reasonRequired,
    resolvedErrorMessage,
    resolvedLoadingMessage,
    resolvedSuccessMessage,
    row,
    showReason,
    showToast,
  ]);

  useEffect(() => {
    if (!open) return undefined;

    const timer = window.setTimeout(() => {
      const element = showReason ? reasonRef.current : cancelRef.current;
      if (!element || typeof element.focus !== "function") return;

      try {
        element.focus({ preventScroll: true });
      } catch (_) {
        element.focus();
      }
    }, 0);

    return () => window.clearTimeout(timer);
  }, [open, showReason]);

  useEffect(() => {
    if (!open) return undefined;

    const onKeyDown = (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation?.();
        close();
        return;
      }

      const targetTag = String(event.target?.tagName || "").toLowerCase();
      if (
        event.key === "Enter" &&
        targetTag !== "textarea" &&
        !isLoading &&
        !confirmDisabled
      ) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation?.();
        handleConfirm();
      }
    };

    document.addEventListener("keydown", onKeyDown, true);
    return () => document.removeEventListener("keydown", onKeyDown, true);
  }, [close, confirmDisabled, handleConfirm, isLoading, open]);

  if (!open) return null;

  return createPortal(
    <>
      {localToast ? (
        <Toast
          duration={localToast.duration}
          key={localToast.id}
          message={localToast.message}
          onClose={() => setLocalToast(null)}
          type={localToast.type}
        />
      ) : null}

      <div
        aria-labelledby="gdel-title"
        aria-modal="true"
        className="gdel-overlay"
        onClick={(event) => event.stopPropagation()}
        onMouseDown={(event) => event.stopPropagation()}
        role="dialog"
      >
        <div
          className={`gdel-modal gdel-modal--${resolvedTone} ${modalClassName}`.trim()}
          onClick={(event) => event.stopPropagation()}
          onMouseDown={(event) => event.stopPropagation()}
        >
          <button
            aria-label="Cerrar"
            className="gdel-close"
            disabled={isLoading}
            onClick={close}
            type="button"
          >
            <GlobalIcon name="close" size={18} />
          </button>

          <div
            aria-hidden="true"
            className={`gdel-icon gdel-icon--${resolvedTone}`}
          >
            <GlobalIcon name={resolvedIcon} size={22} />
          </div>

          <h3
            className={`gdel-title gdel-title--${resolvedTone}`}
            id="gdel-title"
          >
            {resolvedTitle}
          </h3>

          <p className="gdel-body">
            {resolvedMessage}
            {resolvedWarning ? (
              <>
                <br />
                <span>{resolvedWarning}</span>
              </>
            ) : null}
          </p>

          {!hideDefaultCard && resolvedDetails.length > 0 ? (
            <div className="gdel-card">
              {resolvedDetails.map((item) => (
                <div className="gdel-row" key={item.key}>
                  <span className="gdel-label">{item.label}</span>
                  <span className="gdel-value">{item.value}</span>
                </div>
              ))}
            </div>
          ) : null}

          {showReason ? (
            <label
              className={`gdel-reason ${reason.trim() ? "is-active" : ""}`}
            >
              <span className="gdel-reason__label">{reasonLabel}</span>
              <textarea
                disabled={isLoading}
                maxLength={255}
                onChange={(event) =>
                  setReason(toUppercase(event.target.value))
                }
                placeholder={reasonPlaceholder}
                ref={reasonRef}
                rows={3}
                value={reason}
              />
            </label>
          ) : null}

          {extraContent ? (
            <div className="gdel-extraContent">{extraContent}</div>
          ) : null}

          <div className="gdel-actions">
            <button
              className="gdel-btn gdel-btn--ghost"
              disabled={isLoading}
              onClick={close}
              ref={cancelRef}
              type="button"
            >
              {cancelLabel}
            </button>

            <button
              className={`gdel-btn gdel-btn--solid-${resolvedTone}`}
              disabled={isLoading || confirmDisabled}
              onClick={handleConfirm}
              type="button"
            >
              {isLoading ? resolvedLoadingLabel : resolvedConfirmLabel}
            </button>
          </div>
        </div>
      </div>
    </>,
    document.body,
  );
}
