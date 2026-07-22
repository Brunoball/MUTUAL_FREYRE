import React from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCheck, faDiagramProject, faLayerGroup } from "@fortawesome/free-solid-svg-icons";

export default function ModuleScaffold({ module }) {
  return (
    <section className="module-page">
      <header className="module-hero">
        <div>
          <p className="module-hero__eyebrow">{module.eyebrow}</p>
          <h1>{module.title}</h1>
          <p className="module-hero__description">{module.description}</p>
        </div>
        <span className="module-status">{module.status}</span>
      </header>

      <div className="module-grid">
        <article className="module-card module-card--wide">
          <div className="module-card__heading">
            <span className="module-card__icon"><FontAwesomeIcon icon={faLayerGroup} /></span>
            <div><h2>Secciones previstas</h2><p>Submódulos generales definidos desde el relevamiento inicial.</p></div>
          </div>
          <div className="section-grid">
            {module.sections.map((section, index) => (
              <div className="section-tile" key={section}>
                <span>{String(index + 1).padStart(2, "0")}</span>
                <strong>{section}</strong>
              </div>
            ))}
          </div>
        </article>

        <article className="module-card">
          <div className="module-card__heading">
            <span className="module-card__icon"><FontAwesomeIcon icon={faDiagramProject} /></span>
            <div><h2>Reglas de arquitectura</h2><p>Principios que deberá respetar la implementación.</p></div>
          </div>
          <ul className="rule-list">
            {module.rules.map((rule) => (
              <li key={rule}><FontAwesomeIcon icon={faCheck} /><span>{rule}</span></li>
            ))}
          </ul>
        </article>

        <article className="module-card module-card--note">
          <h2>Estado del módulo</h2>
          <p>La carpeta, la ruta, el permiso y el contrato base ya están preparados. La lógica de negocio se incorporará luego del relevamiento funcional y sin modificar la arquitectura general.</p>
        </article>
      </div>
    </section>
  );
}
