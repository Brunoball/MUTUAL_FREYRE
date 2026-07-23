import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";
import GlobalIcon from "./components/GlobalIcon";
import "./Toast.css";

const PERSISTENT_TYPES = new Set(["error", "advertencia", "alerta"]);

const normalizeType = (value) => {
  const type = String(value || "info").toLocaleLowerCase("es-AR");
  if (type === "success" || type === "ok") return "exito";
  if (type === "warning") return "advertencia";
  if (type === "loading") return "cargando";
  return type;
};

const TYPE_CONFIG = {
  exito: { className: "toast-exito", icon: "check" },
  error: { className: "toast-error", icon: "error" },
  advertencia: { className: "toast-advertencia", icon: "warning" },
  alerta: { className: "toast-advertencia", icon: "warning" },
  cargando: { className: "toast-cargando", icon: "loader" },
  info: { className: "toast-info", icon: "info" },
};

export default function Toast({
  type,
  tipo,
  message,
  mensaje,
  duration,
  duracion,
  onClose,
}) {
  const [leaving, setLeaving] = useState(false);
  const closeExecuted = useRef(false);
  const normalizedType = useMemo(
    () => normalizeType(tipo ?? type),
    [tipo, type],
  );
  const config = TYPE_CONFIG[normalizedType] || TYPE_CONFIG.info;
  const persistent = PERSISTENT_TYPES.has(normalizedType);
  const resolvedDuration = duracion ?? duration;
  const resolvedMessage = mensaje ?? message;

  const close = useCallback(() => {
    if (closeExecuted.current) return;
    closeExecuted.current = true;
    setLeaving(true);
    window.setTimeout(() => onClose?.(), 280);
  }, [onClose]);

  useEffect(() => {
    const closeWithEscape = (event) => {
      if (event.key === "Escape") close();
    };

    const closeWithAction = (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;

      const action = target.closest(
        'button, input[type="button"], input[type="submit"], input[type="reset"], [role="button"]',
      );
      if (action) close();
    };

    window.addEventListener("keydown", closeWithEscape);
    document.addEventListener("click", closeWithAction, true);
    return () => {
      window.removeEventListener("keydown", closeWithEscape);
      document.removeEventListener("click", closeWithAction, true);
    };
  }, [close]);

  useEffect(() => {
    if (persistent || normalizedType === "cargando") return undefined;
    if (resolvedDuration === undefined || resolvedDuration === null) {
      return undefined;
    }

    const durationNumber = Math.max(Number(resolvedDuration) || 0, 600);
    const leavingTimer = window.setTimeout(
      () => setLeaving(true),
      Math.max(durationNumber - 500, 0),
    );
    const closeTimer = window.setTimeout(() => onClose?.(), durationNumber);

    return () => {
      window.clearTimeout(leavingTimer);
      window.clearTimeout(closeTimer);
    };
  }, [normalizedType, onClose, persistent, resolvedDuration]);

  if (!resolvedMessage) return null;

  const content = (
    <div
      aria-atomic="true"
      aria-live={normalizedType === "error" ? "assertive" : "polite"}
      className={`toast-container ${config.className} ${leaving ? "desaparecer" : ""}`}
      role={normalizedType === "error" ? "alert" : "status"}
    >
      <GlobalIcon
        className={`toast-icon ${normalizedType === "cargando" ? "is-spinning" : ""}`}
        name={config.icon}
        size={22}
      />
      <span className="toast-message">{resolvedMessage}</span>

      {persistent ? (
        <button
          aria-label="Cerrar notificación"
          className="toast-close"
          onClick={close}
          title="Cerrar"
          type="button"
        >
          <GlobalIcon name="close" size={15} />
        </button>
      ) : null}
    </div>
  );

  return typeof document === "undefined"
    ? content
    : createPortal(content, document.body);
}
