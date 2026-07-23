import React from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faShieldHalved } from "@fortawesome/free-solid-svg-icons";
import "./ForbiddenPage.css";

export default function ForbiddenPage() {
  return (
    <section className="state-page">
      <div className="state-page__icon"><FontAwesomeIcon icon={faShieldHalved} /></div>
      <h1>Acceso restringido</h1>
      <p>Tu usuario no tiene permiso para consultar esta sección.</p>
    </section>
  );
}
