import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import GlobalIcon from "./components/GlobalIcon";

const TYPE_CONFIG = {
  success: { icon: "check", className: "is-success" },
  error: { icon: "error", className: "is-error" },
  warning: { icon: "warning", className: "is-warning" },
  loading: { icon: "loader", className: "is-loading" },
  info: { icon: "info", className: "is-info" },
};

export default function Toast({
  type = "info",
  message,
  duration = 3200,
  onClose,
}) {
  const [leaving, setLeaving] = useState(false);
  const closed = useRef(false);
  const config = useMemo(() => TYPE_CONFIG[type] || TYPE_CONFIG.info, [type]);
  const persistent = type === "loading" || duration === null;

  const close = useCallback(() => {
    if (closed.current) return;
    closed.current = true;
    setLeaving(true);
    window.setTimeout(() => onClose?.(), 220);
  }, [onClose]);

  useEffect(() => {
    if (persistent) return undefined;
    const timer = window.setTimeout(close, Math.max(Number(duration) || 0, 600));
    return () => window.clearTimeout(timer);
  }, [close, duration, persistent]);

  useEffect(() => {
    const closeWithEscape = (event) => {
      if (event.key === "Escape") close();
    };
    window.addEventListener("keydown", closeWithEscape);
    return () => window.removeEventListener("keydown", closeWithEscape);
  }, [close]);

  const content = (
    <div
      aria-atomic="true"
      aria-live={type === "error" ? "assertive" : "polite"}
      className={`global-toast ${config.className} ${leaving ? "is-leaving" : ""}`}
      role={type === "error" ? "alert" : "status"}
    >
      <GlobalIcon
        className={type === "loading" ? "is-spinning" : ""}
        name={config.icon}
        size={21}
      />
      <span>{message}</span>
      {!persistent ? (
        <button aria-label="Cerrar notificación" onClick={close} type="button">
          <GlobalIcon name="close" size={15} />
        </button>
      ) : null}
    </div>
  );

  return typeof document === "undefined" ? content : createPortal(content, document.body);
}
