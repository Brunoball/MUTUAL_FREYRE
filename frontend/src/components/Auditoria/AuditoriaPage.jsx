import React, { useCallback, useEffect, useMemo, useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faArrowLeft,
  faArrowRight,
  faCheckCircle,
  faEye,
  faInfoCircle,
  faMagnifyingGlass,
  faShieldHalved,
  faSpinner,
  faTimesCircle,
  faXmark,
} from "@fortawesome/free-solid-svg-icons";
import ModuleFeedback from "../Global/components/ModuleFeedback";
import {
  getAuditoria,
  getAuditoriaDetalle,
  verificarIntegridadAuditoria,
} from "./api/auditoriaApi";
import "./Auditoria.css";

const EMPTY_FILTERS = {
  buscar: "",
  modulo: "",
  accion: "",
  resultado: "",
  usuario: "",
  desde: "",
  hasta: "",
};

const formatDate = (value) => {
  if (!value) return "Sin fecha";
  const parsed = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(parsed.getTime())) return String(value);
  return new Intl.DateTimeFormat("es-AR", {
    dateStyle: "short",
    timeStyle: "medium",
  }).format(parsed);
};

const humanize = (value) => String(value || "")
  .replaceAll("_", " ")
  .replace(/\b\w/g, (letter) => letter.toUpperCase());

const formatValue = (value) => {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "Sí" : "No";
  if (typeof value === "object") return JSON.stringify(value, null, 2);
  return String(value);
};

function AuditDetailModal({ event, loading, onClose }) {
  if (!event && !loading) return null;
  const changes = event?.cambios?.campos || [];

  return (
    <div className="audit-modal" role="presentation" onMouseDown={onClose}>
      <article
        aria-modal="true"
        className="audit-modal__panel"
        onMouseDown={(mouseEvent) => mouseEvent.stopPropagation()}
        role="dialog"
      >
        <header className="audit-modal__header">
          <div>
            <span>Evento de auditoría</span>
            <h2>{event ? `#${event.id_evento} · ${humanize(event.accion)}` : "Cargando detalle"}</h2>
          </div>
          <button aria-label="Cerrar detalle" onClick={onClose} type="button">
            <FontAwesomeIcon icon={faXmark} />
          </button>
        </header>

        {loading ? (
          <div className="audit-modal__loading">
            <FontAwesomeIcon icon={faSpinner} spin />
            Cargando evidencia...
          </div>
        ) : (
          <div className="audit-modal__content">
            <section className="audit-detail-grid">
              <div><span>Fecha y hora</span><strong>{formatDate(event.creado_en)}</strong></div>
              <div><span>Usuario</span><strong>{event.actor_nombre || event.actor_usuario}</strong><small>@{event.actor_usuario}</small></div>
              <div><span>Rol</span><strong>{event.actor_rol || "Sin snapshot"}</strong></div>
              <div><span>Aplicación</span><strong>{event.aplicacion || "backoffice"}</strong></div>
              <div><span>Módulo</span><strong>{humanize(event.modulo)}</strong></div>
              <div><span>Entidad</span><strong>{event.entidad ? `${humanize(event.entidad)} #${event.id_entidad || "—"}` : "—"}</strong></div>
              <div><span>IP</span><strong>{event.ip || "—"}</strong></div>
              <div><span>Correlation ID</span><strong className="audit-code">{event.correlation_id || "—"}</strong></div>
            </section>

            <section className="audit-detail-section">
              <h3>Qué se hizo</h3>
              <p className="audit-detail-description">{event.descripcion || `${humanize(event.modulo)} / ${humanize(event.accion)}`}</p>
            </section>

            <section className="audit-detail-section">
              <div className="audit-detail-section__title">
                <h3>Cambios registrados</h3>
                <span>{event.cambios?.cantidad || changes.length} campos</span>
              </div>
              {changes.length === 0 ? (
                <p className="audit-empty-detail">El evento no modificó campos comparables o pertenece al historial anterior al blindaje.</p>
              ) : (
                <div className="audit-changes">
                  {changes.map((change, index) => (
                    <article className="audit-change" key={`${change.campo}-${index}`}>
                      <strong>{humanize(change.campo)}</strong>
                      <div>
                        <span>Antes</span>
                        <pre>{formatValue(change.antes)}</pre>
                      </div>
                      <div>
                        <span>Después</span>
                        <pre>{formatValue(change.despues)}</pre>
                      </div>
                    </article>
                  ))}
                </div>
              )}
            </section>

            <section className="audit-detail-section">
              <h3>Información complementaria</h3>
              <pre className="audit-json">{JSON.stringify(event.metadata || {}, null, 2)}</pre>
            </section>

            <section className="audit-integrity-box">
              <FontAwesomeIcon icon={event.sellado ? faCheckCircle : faInfoCircle} />
              <div>
                <strong>{event.sellado ? "Evento sellado criptográficamente" : "Evento histórico sin sello"}</strong>
                <span>
                  {event.sellado
                    ? `SHA-256: ${event.hash_evento}`
                    : "Fue creado antes de la migración de auditoría blindada y se conserva completo."}
                </span>
              </div>
            </section>
          </div>
        )}
      </article>
    </div>
  );
}

export default function AuditoriaPage() {
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [appliedFilters, setAppliedFilters] = useState(EMPTY_FILTERS);
  const [page, setPage] = useState(1);
  const [data, setData] = useState({
    items: [],
    paginacion: { pagina: 1, paginas: 1, total: 0 },
    resumen: {},
    catalogos: { modulos: [], acciones: [], resultados: [], usuarios: [] },
  });
  const [loading, setLoading] = useState(true);
  const [feedback, setFeedback] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [integrity, setIntegrity] = useState(null);
  const [integrityLoading, setIntegrityLoading] = useState(false);

  const load = useCallback(async (signal) => {
    setLoading(true);
    try {
      const response = await getAuditoria(
        { ...appliedFilters, pagina: page, limite: 50 },
        { signal },
      );
      setData(response);
    } catch (error) {
      if (error.name !== "AbortError") {
        setFeedback({ type: "error", message: error.message });
      }
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  }, [appliedFilters, page]);

  useEffect(() => {
    const controller = new AbortController();
    load(controller.signal);
    return () => controller.abort();
  }, [load]);

  const verifyIntegrity = useCallback(async () => {
    setIntegrityLoading(true);
    try {
      const response = await verificarIntegridadAuditoria();
      setIntegrity(response);
      setFeedback({
        type: response.integra ? "success" : "error",
        message: response.integra
          ? `Integridad correcta: ${response.eventos_verificados} eventos sellados verificados.`
          : `La cadena presenta una inconsistencia desde el evento #${response.primer_evento_invalido?.id_evento || "desconocido"}.`,
      });
    } catch (error) {
      setFeedback({ type: "error", message: error.message });
    } finally {
      setIntegrityLoading(false);
    }
  }, []);

  useEffect(() => {
    verifyIntegrity();
  }, [verifyIntegrity]);

  const openDetail = async (eventId) => {
    setDetailLoading(true);
    setDetail({ id_evento: eventId });
    try {
      setDetail(await getAuditoriaDetalle(eventId));
    } catch (error) {
      setDetail(null);
      setFeedback({ type: "error", message: error.message });
    } finally {
      setDetailLoading(false);
    }
  };

  const submitFilters = (event) => {
    event.preventDefault();
    setPage(1);
    setAppliedFilters(filters);
  };

  const clearFilters = () => {
    setFilters(EMPTY_FILTERS);
    setAppliedFilters(EMPTY_FILTERS);
    setPage(1);
  };

  const resultClass = (result) => {
    if (result === "success") return "is-success";
    if (result === "failed" || result === "rejected" || result === "blocked") return "is-error";
    return "is-neutral";
  };

  const canPrevious = page > 1;
  const canNext = page < (data.paginacion?.paginas || 1);
  const users = useMemo(() => data.catalogos?.usuarios || [], [data.catalogos]);

  return (
    <section className="audit-page">
      <header className="audit-page__header">
        <div className="audit-page__title">
          <span className="audit-page__icon"><FontAwesomeIcon icon={faShieldHalved} /></span>
          <div>
            <span>Control y evidencia</span>
            <h1>Auditoría del sistema</h1>
            <p>Cada operación registra quién la hizo, qué cambió, cuándo, desde dónde y su evidencia antes/después.</p>
          </div>
        </div>
        <button
          className={`audit-integrity-button ${integrity?.integra ? "is-valid" : integrity ? "is-invalid" : ""}`}
          disabled={integrityLoading}
          onClick={verifyIntegrity}
          type="button"
        >
          <FontAwesomeIcon icon={integrityLoading ? faSpinner : integrity?.integra ? faCheckCircle : faShieldHalved} spin={integrityLoading} />
          {integrityLoading ? "Verificando..." : integrity?.integra ? "Integridad verificada" : "Verificar integridad"}
        </button>
      </header>

      <div className="audit-summary">
        <article><span>Eventos registrados</span><strong>{data.resumen?.total || 0}</strong></article>
        <article><span>Últimas 24 horas</span><strong>{data.resumen?.ultimas_24h || 0}</strong></article>
        <article><span>Usuarios detectados</span><strong>{data.resumen?.actores || 0}</strong></article>
        <article className={data.resumen?.no_exitosos ? "has-warning" : ""}><span>Fallidos o bloqueados</span><strong>{data.resumen?.no_exitosos || 0}</strong></article>
      </div>

      {integrity ? (
        <section className={`audit-integrity ${integrity.integra ? "is-valid" : "is-invalid"}`}>
          <FontAwesomeIcon icon={integrity.integra ? faCheckCircle : faTimesCircle} />
          <div>
            <strong>{integrity.integra ? "La cadena de auditoría está íntegra" : "Se detectó una alteración en la cadena"}</strong>
            <span>
              {integrity.eventos_verificados} eventos sellados verificados · {integrity.eventos_legacy_sin_sello} históricos anteriores al blindaje
            </span>
          </div>
        </section>
      ) : null}

      <form className="audit-filters" onSubmit={submitFilters}>
        <label className="audit-filter audit-filter--search">
          <span>Buscar evidencia</span>
          <div><FontAwesomeIcon icon={faMagnifyingGlass} /><input value={filters.buscar} onChange={(event) => setFilters((current) => ({ ...current, buscar: event.target.value }))} placeholder="Usuario, descripción, entidad o correlation ID" /></div>
        </label>
        <label className="audit-filter"><span>Módulo</span><select value={filters.modulo} onChange={(event) => setFilters((current) => ({ ...current, modulo: event.target.value }))}><option value="">Todos</option>{(data.catalogos?.modulos || []).map((item) => <option key={item} value={item}>{humanize(item)}</option>)}</select></label>
        <label className="audit-filter"><span>Acción</span><select value={filters.accion} onChange={(event) => setFilters((current) => ({ ...current, accion: event.target.value }))}><option value="">Todas</option>{(data.catalogos?.acciones || []).map((item) => <option key={item} value={item}>{humanize(item)}</option>)}</select></label>
        <label className="audit-filter"><span>Usuario</span><select value={filters.usuario} onChange={(event) => setFilters((current) => ({ ...current, usuario: event.target.value }))}><option value="">Todos</option>{users.map((item) => <option key={item.usuario} value={item.usuario}>{item.nombre || item.usuario} (@{item.usuario})</option>)}</select></label>
        <label className="audit-filter"><span>Resultado</span><select value={filters.resultado} onChange={(event) => setFilters((current) => ({ ...current, resultado: event.target.value }))}><option value="">Todos</option>{(data.catalogos?.resultados || []).map((item) => <option key={item} value={item}>{humanize(item)}</option>)}</select></label>
        <label className="audit-filter"><span>Desde</span><input type="date" value={filters.desde} onChange={(event) => setFilters((current) => ({ ...current, desde: event.target.value }))} /></label>
        <label className="audit-filter"><span>Hasta</span><input type="date" value={filters.hasta} onChange={(event) => setFilters((current) => ({ ...current, hasta: event.target.value }))} /></label>
        <div className="audit-filter-actions">
          <button className="is-primary" type="submit"><FontAwesomeIcon icon={faMagnifyingGlass} /> Aplicar</button>
          <button onClick={clearFilters} type="button">Limpiar</button>
        </div>
      </form>

      <article className="audit-table-card">
        <header>
          <div><h2>Historial inmutable</h2><p>Los eventos sellados no pueden editarse ni eliminarse desde la aplicación.</p></div>
          <strong>{data.paginacion?.total || 0} eventos</strong>
        </header>
        <div className="audit-table-wrap">
          <table className="audit-table">
            <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción realizada</th><th>Entidad</th><th>Resultado</th><th>Evidencia</th></tr></thead>
            <tbody>
              {loading ? (
                <tr><td className="audit-table__message" colSpan={6}><FontAwesomeIcon icon={faSpinner} spin /> Cargando auditoría...</td></tr>
              ) : data.items?.length === 0 ? (
                <tr><td className="audit-table__message" colSpan={6}>No hay eventos para los filtros elegidos.</td></tr>
              ) : data.items.map((event) => (
                <tr key={event.id_evento}>
                  <td><strong>{formatDate(event.creado_en)}</strong><small>#{event.id_evento}</small></td>
                  <td><div className="audit-actor"><strong>{event.actor_nombre || event.actor_usuario}</strong><span>@{event.actor_usuario} · {event.actor_rol || "Sin rol"}</span></div></td>
                  <td><div className="audit-action"><span>{humanize(event.modulo)} · {humanize(event.accion)}</span><strong>{event.descripcion || "Evento histórico"}</strong></div></td>
                  <td>{event.entidad ? <div className="audit-entity"><strong>{humanize(event.entidad)}</strong><span>#{event.id_entidad || "—"}</span></div> : "—"}</td>
                  <td><span className={`audit-result ${resultClass(event.resultado)}`}>{humanize(event.resultado)}</span></td>
                  <td><button aria-label={`Ver evento ${event.id_evento}`} className="audit-view-button" onClick={() => openDetail(event.id_evento)} title="Ver cambios y evidencia" type="button"><FontAwesomeIcon icon={faEye} /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <footer className="audit-pagination">
          <span>Página {data.paginacion?.pagina || 1} de {data.paginacion?.paginas || 1}</span>
          <div>
            <button disabled={!canPrevious || loading} onClick={() => setPage((current) => Math.max(1, current - 1))} type="button"><FontAwesomeIcon icon={faArrowLeft} /> Anterior</button>
            <button disabled={!canNext || loading} onClick={() => setPage((current) => current + 1)} type="button">Siguiente <FontAwesomeIcon icon={faArrowRight} /></button>
          </div>
        </footer>
      </article>

      <ModuleFeedback {...(feedback || {})} onClose={() => setFeedback(null)} />
      <AuditDetailModal event={detail} loading={detailLoading} onClose={() => setDetail(null)} />
    </section>
  );
}
