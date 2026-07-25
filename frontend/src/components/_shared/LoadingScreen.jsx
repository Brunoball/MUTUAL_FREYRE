import React from "react";
import "./LoadingScreen.css";

export default function LoadingScreen({ text = "Cargando..." }) {
  return (
    <div className="loading-screen" role="status" aria-live="polite">
      <span className="loading-screen__spinner" />
      <span>{text}</span>
    </div>
  );
}
