import React, { useEffect, useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faChevronRight,
  faCircleCheck,
  faGear,
  faUsers,
} from "@fortawesome/free-solid-svg-icons";
import { useNavigate } from "react-router-dom";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { getConfiguracion } from "./configuracion.api";
import "./Configuracion.css";

const FALLBACK_SECTION = {
  codigo: "usuarios",
  titulo: "Usuarios del sistema",
  descripcion: "Creá usuarios y asigná roles para controlar el acceso al sistema interno.",
  estado: "Administrable",
  detalle: "Gestión de usuarios y roles",
  ruta: "/configuracion/usuarios",
};

export default function ConfiguracionPage() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [feedback, setFeedback] = useState(null);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    getConfiguracion({ signal: controller.signal })
      .then(setData)
      .catch((error) => {
        if (error.name === "AbortError") return;
        setFeedback({ type: "error", message: error.message });
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, []);

  const sections = data?.secciones?.length ? data.secciones : [FALLBACK_SECTION];

  return (
    <section className="configuration-page">
      <header className="configuration-page__header">
        <span className="configuration-page__header-icon">
          <FontAwesomeIcon icon={faGear} />
        </span>
        <div>
          <span>Configuración global</span>
          <h1>Configuración</h1>
          <p>
            Administrá los accesos y parámetros generales del sistema interno de la mutual.
          </p>
        </div>
      </header>

      <div className="configuration-grid" aria-busy={loading}>
        {sections.map((section) => (
          <button
            className="configuration-card"
            key={section.codigo}
            onClick={() => navigate(section.ruta || "/configuracion/usuarios")}
            type="button"
          >
            <div className="configuration-card__top">
              <span className="configuration-card__icon">
                <FontAwesomeIcon icon={faUsers} />
              </span>
              <div className="configuration-card__copy">
                <h2>{section.titulo}</h2>
                <p>{section.descripcion}</p>
              </div>
              <span className="configuration-card__badge">
                <FontAwesomeIcon icon={faCircleCheck} />
                {section.estado}
              </span>
            </div>

            <div className="configuration-card__bottom">
              <div>
                <span>Estado</span>
                <strong>Roles y accesos disponibles</strong>
              </div>
              <div>
                <span>Detalle</span>
                <strong>{loading ? "Cargando usuarios..." : section.detalle}</strong>
              </div>
              <FontAwesomeIcon icon={faChevronRight} />
            </div>
          </button>
        ))}
      </div>

      {feedback ? (
        <ModuleFeedback
          message={feedback.message}
          onClose={() => setFeedback(null)}
          type={feedback.type}
        />
      ) : null}
    </section>
  );
}
