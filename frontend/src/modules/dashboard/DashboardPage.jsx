import React from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faArrowRight, faCubes, faDatabase, faLock, faRoute } from "@fortawesome/free-solid-svg-icons";
import { useNavigate } from "react-router-dom";
import { MODULE_CATALOG } from "../../config/moduleCatalog";
import { useAuth } from "../../app/AuthProvider";
import "./Dashboard.css";

const highlights = [
  { icon: faCubes, title: "Monolito modular", text: "Un backend desplegable, dividido por dominios de negocio independientes." },
  { icon: faDatabase, title: "Base única", text: "Una única base institucional, sin selección dinámica de organizaciones." },
  { icon: faLock, title: "Seguridad transversal", text: "Sesiones HttpOnly, CSRF, permisos, bloqueo de login y auditoría desde el inicio." },
  { icon: faRoute, title: "Contratos estables", text: "API versionada y rutas preparadas para crecer sin volver al api.php?action." },
];

const quickModules = [
  ["personas", "/personas"],
  ["ayudas", "/ayudas"],
  ["cuentas", "/cuentas"],
  ["caja", "/caja"],
  ["contabilidad", "/contabilidad"],
  ["configuracion", "/configuracion"],
];

export default function DashboardPage() {
  const navigate = useNavigate();
  const { usuario, can } = useAuth();
  return (
    <section className="dashboard-page">
      <header className="dashboard-hero">
        <div>
          <p className="dashboard-hero__eyebrow">Fundación técnica · Etapa inicial</p>
          <h1>Buen día, {usuario?.nombre || usuario?.username || "usuario"}</h1>
          <p>La estructura quedó preparada para construir el sistema financiero-administrativo de la mutual por módulos, sin arrastrar la arquitectura SaaS anterior.</p>
        </div>
        <div className="dashboard-hero__badge"><strong>14</strong><span>dominios definidos</span></div>
      </header>

      <div className="architecture-grid">
        {highlights.map((item) => (
          <article className="architecture-card" key={item.title}>
            <span><FontAwesomeIcon icon={item.icon} /></span>
            <h2>{item.title}</h2>
            <p>{item.text}</p>
          </article>
        ))}
      </div>

      <section className="dashboard-section">
        <div className="dashboard-section__heading">
          <div><p>Mapa funcional</p><h2>Módulos principales</h2></div>
          <span>Las pantallas son base estructural; todavía no contienen lógica financiera.</span>
        </div>
        <div className="quick-grid">
          {quickModules.filter(([key]) => can(MODULE_CATALOG[key].permission)).map(([key, path]) => {
            const module = MODULE_CATALOG[key];
            return (
              <button className="quick-card" type="button" onClick={() => navigate(path)} key={key}>
                <div><span>{module.eyebrow}</span><strong>{module.title}</strong><p>{module.description}</p></div>
                <FontAwesomeIcon icon={faArrowRight} />
              </button>
            );
          })}
        </div>
      </section>
    </section>
  );
}
