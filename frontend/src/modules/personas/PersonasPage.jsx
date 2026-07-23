import React, { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "../../app/AuthProvider";
import { catalogToOptions, GLOBAL_OPTIONS } from "../../config/globalOptions";
import {
  EMPTY_PERSONAS_CATALOGS,
  normalizePersonasCatalogs,
} from "../../config/globalCatalogs";
import { MODULE_CATALOG } from "../../config/moduleCatalog";
import GlobalDivTable from "../../Global/components/GlobalDivTable";
import CrudModal from "../../Global/components/CrudModal";
import GlobalIcon from "../../Global/components/GlobalIcon";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { ModulePage } from "../../Global/components/ModulePage";

import PersonaModal from "./PersonaModal";
import {
  actualizarPersona,
  cambiarEstadoPersona,
  crearPersona,
  getPersonaDetalle,
  getPersonas,
  getPersonasCatalogos,
} from "./personas.api";
import "./personas.css";

const isTrue = (value) => value === true || value === 1 || value === "1";
const typeLabel = (value) => (value === "JURIDICA" ? "Jurídica" : "Física");
const UI_ACRONYMS = /\b(dni|cuit|cuil|iva|pep|arca|inaes|cbu)\b/gi;

const toUiLabel = (value) => {
  const text = String(value ?? "").trim();
  if (!text) return text;
  const normalized = `${text.charAt(0).toLocaleUpperCase("es-AR")}${text.slice(1).toLocaleLowerCase("es-AR")}`;
  return normalized.replace(UI_ACRONYMS, (match) =>
    match.toLocaleUpperCase("es-AR"),
  );
};

const uiOptions = (options = []) =>
  options.map((option) => ({
    ...option,
    label: toUiLabel(option.label),
  }));

export default function PersonasPage() {
  const moduleConfig = MODULE_CATALOG.personas || {};
  const { can } = useAuth();
  const canManage = can("personas.manage");
  const requestRef = useRef(0);
  const timerRef = useRef(null);

  const [records, setRecords] = useState([]);
  const [catalogs, setCatalogs] = useState(EMPTY_PERSONAS_CATALOGS);
  const [loading, setLoading] = useState(true);
  const [catalogsLoading, setCatalogsLoading] = useState(true);
  const [feedback, setFeedback] = useState(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("ACTIVAS");
  const [type, setType] = useState("");
  const [localityId, setLocalityId] = useState("");
  const [membership, setMembership] = useState("");

  const [modal, setModal] = useState(null);
  const [modalLoading, setModalLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formErrors, setFormErrors] = useState({});
  const [stateModal, setStateModal] = useState(null);
  const [stateReason, setStateReason] = useState("");
  const [changingStatus, setChangingStatus] = useState(false);

  const loadCatalogs = useCallback(async () => {
    setCatalogsLoading(true);
    try {
      const response = await getPersonasCatalogos();
      setCatalogs(normalizePersonasCatalogs(response));
    } catch (error) {
      setFeedback({
        type: "error",
        message:
          error?.message || "No se pudieron cargar los selectores del sistema.",
      });
    } finally {
      setCatalogsLoading(false);
    }
  }, []);

  const load = useCallback(
    async ({ notify = false, query = search } = {}) => {
      const requestId = ++requestRef.current;
      setLoading(true);
      try {
        const response = await getPersonas({
          buscar: query,
          estado: status,
          tipo: type,
          id_localidad: localityId,
          asociado: membership,
          limite: 300,
        });
        if (requestId !== requestRef.current) return;
        setRecords(Array.isArray(response?.items) ? response.items : []);
        if (notify)
          setFeedback({
            type: "success",
            message: "Listado actualizado correctamente.",
          });
      } catch (error) {
        if (requestId !== requestRef.current) return;
        setFeedback({
          type: "error",
          message: error?.message || "No se pudo cargar el padrón.",
        });
      } finally {
        if (requestId === requestRef.current) setLoading(false);
      }
    },
    [localityId, membership, search, status, type],
  );

  useEffect(() => {
    loadCatalogs();
  }, [loadCatalogs]);

  useEffect(() => {
    clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => load({ query: search }), 250);
    return () => clearTimeout(timerRef.current);
  }, [load, search]);

  const openCreate = () => {
    if (catalogsLoading) {
      setFeedback({
        type: "info",
        message:
          "Esperá un momento: se están cargando los selectores del sistema.",
      });
      return;
    }
    if (!catalogs.paises.length) {
      setFeedback({
        type: "error",
        message:
          "No se puede abrir el alta porque no se cargaron los selectores de la base de datos.",
      });
      return;
    }
    setFormErrors({});
    setModal({ mode: "create", detail: null });
  };

  const openExisting = async (item, mode) => {
    setFormErrors({});
    setModalLoading(true);
    try {
      const detail = await getPersonaDetalle(item.id_persona);
      setModal({ mode, detail });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo abrir el legajo.",
      });
    } finally {
      setModalLoading(false);
    }
  };

  const closeModal = () => {
    if (saving) return;
    setModal(null);
    setFormErrors({});
  };

  const savePerson = async (payload) => {
    setSaving(true);
    setFormErrors({});
    try {
      if (modal?.mode === "create") {
        await crearPersona(payload);
        setFeedback({
          type: "success",
          message: "La persona fue creada correctamente.",
        });
      } else {
        await actualizarPersona(modal?.detail?.persona?.id_persona, payload);
        setFeedback({
          type: "success",
          message: "El legajo fue actualizado correctamente.",
        });
      }
      setModal(null);
      await Promise.all([load({ query: search }), loadCatalogs()]);
    } catch (error) {
      setFormErrors(error?.fields || {});
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo guardar el legajo.",
      });
    } finally {
      setSaving(false);
    }
  };

  const openStateModal = (item) => {
    setStateReason("");
    setStateModal(item);
  };

  const closeStateModal = () => {
    if (changingStatus) return;
    setStateModal(null);
    setStateReason("");
  };

  const confirmStatusChange = async (event) => {
    event.preventDefault();
    if (!stateModal || changingStatus) return;

    const currentlyActive = isTrue(stateModal.activo);
    if (currentlyActive && !stateReason.trim()) {
      setFeedback({ type: "warning", message: "Indicá el motivo de la baja." });
      return;
    }

    setChangingStatus(true);
    try {
      await cambiarEstadoPersona(
        stateModal.id_persona,
        !currentlyActive,
        stateReason.trim(),
      );
      setFeedback({
        type: "success",
        message: currentlyActive
          ? "Persona dada de baja correctamente."
          : "Persona reactivada correctamente.",
      });
      setStateModal(null);
      setStateReason("");
      await load({ query: search });
    } catch (error) {
      setFeedback({
        type: "error",
        message: error?.message || "No se pudo actualizar el estado.",
      });
    } finally {
      setChangingStatus(false);
    }
  };

  const filters = [
    {
      key: "status",
      label: "Estado",
      type: "tabs",
      value: status,
      onChange: setStatus,
      options: uiOptions(
        GLOBAL_OPTIONS.statusFilters.filter(
          (option) => option.value !== "TODAS",
        ),
      ),
    },
    {
      key: "search",
      label: "Búsqueda",
      type: "search",
      alwaysFloatLabel: true,
      placeholder: "Nombre, DNI, CUIT o N.º de socio",
      value: search,
      onChange: setSearch,
    },
    {
      key: "type",
      label: "Tipo",
      type: "select",
      placeholder: "Todos",
      value: type,
      onChange: setType,
      options: uiOptions(GLOBAL_OPTIONS.personTypes),
    },
    {
      key: "membership",
      label: "Asociación",
      type: "select",
      placeholder: "Todas",
      value: membership,
      onChange: setMembership,
      options: uiOptions(GLOBAL_OPTIONS.membershipFilters),
    },
    {
      key: "locality",
      label: "Localidad",
      type: "select",
      placeholder: "Todas",
      value: localityId,
      onChange: setLocalityId,
      options: catalogToOptions(catalogs.localidades),
    },
  ];

  return (
    <>
      <ModulePage
        canCreate={canManage}
        filters={filters}
        onPrimaryAction={openCreate}
        primaryActionLabel="Nueva persona"
        tabsInTitle
        title={toUiLabel(moduleConfig.title || "Personas y asociados")}
      >
        <ModuleFeedback
          duration={feedback?.duration}
          message={feedback?.message}
          onClose={() => setFeedback(null)}
          type={feedback?.type}
        />

        <GlobalDivTable
          ariaLabel="Listado de personas y asociados"
          bodyClassName="personas-global-table__body"
          className="personas-global-table"
          columns={[
            { label: "Socio", className: "is-center" },
            "Persona",
            { label: "Documento", className: "is-center" },
            { label: "Tipo", className: "is-center" },
            "Localidad",
            { label: "Estado", className: "is-center" },
            { label: "Acciones", className: "is-center" },
          ]}
          gridClassName="personas-global-grid"
        >
          {loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon className="is-spinning" name="loader" size={28} />
              <strong>Cargando padrón...</strong>
              <span>Consultando personas físicas, jurídicas y asociados.</span>
            </div>
          ) : null}

          {!loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon name="inbox" size={30} />
              <strong>Sin personas para mostrar</strong>
              <span>
                Creá la primera persona o modificá los filtros aplicados.
              </span>
            </div>
          ) : null}

          {records.map((item) => {
            const active = isTrue(item.activo);
            return (
              <div
                className="global-div-table__row personas-global-grid"
                key={item.id_persona}
                role="row"
              >
                <div
                  className="global-table-cell is-center is-strong"
                  role="cell"
                >
                  {item.id_asociado ? `#${item.id_asociado}` : "—"}
                </div>
                <div
                  className="global-table-cell global-table-cell--main"
                  role="cell"
                >
                  <strong>{item.nombre_exhibicion}</strong>
                  <small>
                    {item.id_asociado
                      ? `${item.categoria || "Sin categoría"} · ${toUiLabel(item.estado_asociado)}`
                      : "Persona no asociada"}
                  </small>
                </div>
                <div
                  className="global-table-cell is-center is-strong"
                  role="cell"
                >
                  {item.dni || item.cuit_cuil || "—"}
                </div>
                <div className="global-table-cell is-center" role="cell">
                  {typeLabel(item.tipo_persona)}
                </div>
                <div
                  className="global-table-cell global-table-cell--main"
                  role="cell"
                >
                  <span>{item.localidad || "—"}</span>
                  {item.provincia ? <small>{item.provincia}</small> : null}
                </div>
                <div className="global-table-cell is-center" role="cell">
                  <span
                    className={`global-chip ${active ? "is-success" : "is-danger"}`}
                  >
                    {active ? "ACTIVA" : "BAJA"}
                  </span>
                </div>
                <div
                  className="global-table-cell global-table-cell--actions"
                  role="cell"
                >
                  <div className="global-table-actions">
                    <button
                      className="global-icon-button"
                      onClick={() => openExisting(item, "view")}
                      title="Ver ficha"
                      type="button"
                    >
                      <GlobalIcon name="eye" size={15} />
                    </button>
                    {canManage ? (
                      <>
                        <button
                          className="global-icon-button"
                          onClick={() => openExisting(item, "edit")}
                          title="Editar"
                          type="button"
                        >
                          <GlobalIcon name="edit" size={15} />
                        </button>
                        <button
                          className={`global-icon-button ${active ? "is-danger" : "is-success"}`}
                          onClick={() => openStateModal(item)}
                          title={active ? "Dar de baja" : "Reactivar"}
                          type="button"
                        >
                          <GlobalIcon
                            name={active ? "disable" : "enable"}
                            size={16}
                          />
                        </button>
                      </>
                    ) : null}
                  </div>
                </div>
              </div>
            );
          })}
        </GlobalDivTable>
      </ModulePage>

      {modalLoading ? (
        <div className="entity-modal-overlay">
          <div className="persona-modal-loading">
            <GlobalIcon className="is-spinning" name="loader" size={28} />
            <span>Cargando legajo...</span>
          </div>
        </div>
      ) : null}

      {modal ? (
        <PersonaModal
          catalogs={catalogs}
          detail={modal.detail}
          errors={formErrors}
          mode={modal.mode}
          onClose={closeModal}
          onSave={savePerson}
          saving={saving}
        />
      ) : null}

      <CrudModal
        danger={isTrue(stateModal?.activo)}
        modalClassName="persona-state-modal"
        onClose={closeStateModal}
        onSubmit={confirmStatusChange}
        open={Boolean(stateModal)}
        saving={changingStatus}
        savingLabel={
          isTrue(stateModal?.activo) ? "Dando de baja..." : "Reactivando..."
        }
        submitDisabled={isTrue(stateModal?.activo) && !stateReason.trim()}
        submitLabel={isTrue(stateModal?.activo) ? "Dar de baja" : "Reactivar"}
        subtitle={
          isTrue(stateModal?.activo)
            ? "La persona dejará de figurar como activa, pero se conservarán sus datos y vínculos."
            : "La persona volverá a estar disponible para nuevas operaciones."
        }
        title={
          isTrue(stateModal?.activo)
            ? "Dar de baja a la persona"
            : "Reactivar persona"
        }
      >
        <div
          className={`persona-state-confirm ${isTrue(stateModal?.activo) ? "is-danger" : "is-success"}`}
        >
          <span className="persona-state-confirm__icon">
            <GlobalIcon
              name={isTrue(stateModal?.activo) ? "warning" : "check"}
              size={24}
            />
          </span>
          <p>
            {isTrue(stateModal?.activo)
              ? "Confirmá la baja e indicá el motivo para dejar registro de la operación."
              : "Confirmá que querés reactivar este registro."}
          </p>
          <dl className="persona-state-confirm__details">
            <div>
              <dt>Persona</dt>
              <dd>{stateModal?.nombre_exhibicion || "—"}</dd>
            </div>
            <div>
              <dt>Documento</dt>
              <dd>{stateModal?.dni || stateModal?.cuit_cuil || "—"}</dd>
            </div>
            <div>
              <dt>Estado actual</dt>
              <dd>{isTrue(stateModal?.activo) ? "ACTIVA" : "BAJA"}</dd>
            </div>
          </dl>
          {isTrue(stateModal?.activo) ? (
            <label
              className={`entity-field is-textarea ${stateReason ? "is-active" : ""}`.trim()}
            >
              <textarea
                autoFocus
                onChange={(event) => setStateReason(event.target.value)}
                placeholder=" "
                rows="3"
                value={stateReason}
              />
              <span>Motivo de baja *</span>
            </label>
          ) : null}
        </div>
      </CrudModal>
    </>
  );
}
