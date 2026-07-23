import React, { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "../../app/AuthProvider";
import { catalogToOptions, GLOBAL_OPTIONS } from "../../config/globalOptions";
import {
  EMPTY_PERSONAS_CATALOGS,
  normalizePersonasCatalogs,
} from "../../config/globalCatalogs";
import { MODULE_CATALOG } from "../../config/moduleCatalog";
import GlobalDivTable from "../../Global/components/GlobalDivTable";
import GlobalActionIcon from "../../Global/components/GlobalActionIcon";
import GlobalIcon from "../../Global/components/GlobalIcon";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { ModulePage } from "../../Global/components/ModulePage";
import ModalEliminarGlobal from "../../Global/Modales/ModalEliminarGlobal";

import PersonaModal from "./PersonaModal";
import {
  actualizarPersona,
  cambiarEstadoPersona,
  crearPersona,
  getPersonaDetalle,
  getPersonaVinculosImpacto,
  getPersonas,
  getPersonasCatalogos,
} from "./personas.api";
import "./personas.css";

const isTrue = (value) => value === true || value === 1 || value === "1";
const typeLabel = (value) =>
  value === "JURIDICA" ? "PERSONA JURÍDICA" : "PERSONA FÍSICA";

const localDateValue = (date = new Date()) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};


const formatDate = (value) => {
  const text = String(value ?? "").slice(0, 10);
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
  return match ? `${match[3]}/${match[2]}/${match[1]}` : "—";
};

const documentKind = (item) => {
  if (item?.dni) return "DNI";
  return item?.tipo_persona === "JURIDICA" ? "CUIT" : "CUIT / CUIL";
};
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

const EMPTY_LINK_IMPACT = {
  total: 0,
  como_titular: 0,
  como_vinculada: 0,
  items: [],
};

const linkTypeLabel = (value) =>
  String(value || "VÍNCULO")
    .replaceAll("_", " ")
    .toLocaleUpperCase("es-AR");

export default function PersonasPage() {
  const moduleConfig = MODULE_CATALOG.personas || {};
  const { can } = useAuth();
  const canManage = can("personas.manage");
  const requestRef = useRef(0);
  const stateImpactRequestRef = useRef(0);
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
  const [stateDate, setStateDate] = useState(() => localDateValue());
  const [stateImpact, setStateImpact] = useState(EMPTY_LINK_IMPACT);
  const [stateImpactLoading, setStateImpactLoading] = useState(false);
  const [stateImpactConfirmed, setStateImpactConfirmed] = useState(false);
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

  const clearFormError = (path) => {
    setFormErrors((current) => {
      const next = { ...current };
      Object.keys(next).forEach((key) => {
        if (key === path || key.startsWith(`${path}.`)) delete next[key];
      });
      if (path.startsWith("beneficiarios.")) delete next.beneficiarios;
      return next;
    });
  };

  const showValidationErrors = (nextErrors) => {
    setFormErrors(nextErrors || {});
    setFeedback({
      type: "error",
      message:
        "Revisá los campos marcados en rojo. La pestaña que contiene el error también quedó resaltada.",
    });
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

  const openStateModal = async (item) => {
    setStateDate(localDateValue());
    setStateImpact(EMPTY_LINK_IMPACT);
    setStateImpactConfirmed(false);
    setStateModal(item);

    if (!isTrue(item?.activo)) return;

    const requestId = ++stateImpactRequestRef.current;
    setStateImpactLoading(true);
    try {
      const response = await getPersonaVinculosImpacto(item.id_persona);
      if (requestId !== stateImpactRequestRef.current) return;
      setStateImpact({
        ...EMPTY_LINK_IMPACT,
        ...(response || {}),
        items: Array.isArray(response?.items) ? response.items : [],
      });
    } catch (error) {
      if (requestId !== stateImpactRequestRef.current) return;
      setStateModal(null);
      setFeedback({
        type: "error",
        message:
          error?.message ||
          "No se pudieron verificar los vínculos de la persona.",
      });
    } finally {
      if (requestId === stateImpactRequestRef.current) {
        setStateImpactLoading(false);
      }
    }
  };

  const closeStateModal = () => {
    stateImpactRequestRef.current += 1;
    setStateModal(null);
    setStateDate(localDateValue());
    setStateImpact(EMPTY_LINK_IMPACT);
    setStateImpactConfirmed(false);
    setStateImpactLoading(false);
  };

  const confirmStatusChange = async ({ motivo = "" } = {}) => {
    if (!stateModal || changingStatus) return;

    const currentlyActive = isTrue(stateModal.activo);
    if (currentlyActive && !stateDate) {
      throw new Error("Indicá la fecha de la baja.");
    }
    if (currentlyActive && stateDate > localDateValue()) {
      throw new Error("La fecha de baja no puede ser posterior al día de hoy.");
    }
    if (
      currentlyActive &&
      Number(stateImpact.total || 0) > 0 &&
      !stateImpactConfirmed
    ) {
      throw new Error(
        "Confirmá que revisaste los vínculos activos antes de continuar.",
      );
    }

    setChangingStatus(true);
    try {
      await cambiarEstadoPersona(
        stateModal.id_persona,
        !currentlyActive,
        motivo.trim(),
        currentlyActive ? stateDate : null,
        currentlyActive && stateImpactConfirmed,
      );
      await Promise.all([load({ query: search }), loadCatalogs()]);
    } catch (error) {
      if (error?.code === "ACTIVE_LINKS_REQUIRE_CONFIRMATION") {
        try {
          const response = await getPersonaVinculosImpacto(
            stateModal.id_persona,
          );
          setStateImpact({
            ...EMPTY_LINK_IMPACT,
            ...(response || {}),
            items: Array.isArray(response?.items) ? response.items : [],
          });
          setStateImpactConfirmed(false);
        } catch (_) {
          // Se mantiene el error original de concurrencia.
        }
      }
      throw new Error(error?.message || "No se pudo actualizar el estado.");
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
      placeholder: "TODOS",
      value: type,
      onChange: setType,
      options: GLOBAL_OPTIONS.personTypes,
    },
    {
      key: "membership",
      label: "Asociación",
      type: "select",
      placeholder: "TODAS",
      value: membership,
      onChange: setMembership,
      options: GLOBAL_OPTIONS.membershipFilters,
    },
    {
      key: "locality",
      label: "Localidad",
      type: "select",
      placeholder: "TODAS",
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
            "Persona",
            { label: "Documento", className: "is-center" },
            "Contacto",
            "Ubicación",
            "Asociación",
            {
              label: status === "BAJAS" ? "Fecha de baja" : "Alta / registro",
              className: "is-center",
            },
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
            const document = item.dni || item.cuit_cuil || "—";
            const associated = Boolean(item.id_asociado);
            const relevantDate = active
              ? item.fecha_ingreso || item.creado_en
              : item.fecha_baja;
            const relevantDateLabel = active
              ? associated
                ? "INGRESO COMO SOCIO"
                : "REGISTRO DE PERSONA"
              : item.motivo_baja || "BAJA REGISTRADA";

            return (
              <div
                className="global-div-table__row personas-global-grid personas-table-row"
                key={item.id_persona}
                role="row"
              >
                <div
                  className="global-table-cell global-table-cell--main personas-person-cell"
                  role="cell"
                >
                  <strong>{item.nombre_exhibicion}</strong>
                  <span className="personas-table-meta">
                    {typeLabel(item.tipo_persona)}
                  </span>
                </div>

                <div
                  className="global-table-cell is-center personas-document-cell"
                  role="cell"
                >
                  <strong>{document}</strong>
                  <small>{documentKind(item)}</small>
                </div>

                <div
                  className="global-table-cell global-table-cell--main personas-contact-cell"
                  role="cell"
                >
                  <span className="personas-table-line">
                    {item.telefono || "SIN TELÉFONO"}
                  </span>
                  <small className="personas-table-line" title={item.email || ""}>
                    {item.email || "SIN CORREO"}
                  </small>
                </div>

                <div
                  className="global-table-cell global-table-cell--main personas-location-cell"
                  role="cell"
                >
                  <span className="personas-table-line" title={item.domicilio || ""}>
                    {item.domicilio || "SIN DOMICILIO"}
                  </span>
                  <small>
                    {[item.localidad, item.provincia].filter(Boolean).join(" · ") ||
                      "SIN LOCALIDAD"}
                  </small>
                </div>

                <div
                  className="global-table-cell global-table-cell--main personas-membership-cell"
                  role="cell"
                >
                  <span
                    className={`personas-membership-chip ${
                      associated ? "is-associated" : "is-unassociated"
                    }`}
                  >
                    {associated ? "ASOCIADO" : "NO ASOCIADO"}
                  </span>
                  <small title={associated ? item.categoria || "" : ""}>
                    {associated
                      ? [item.categoria, item.sucursal, item.estado_asociado]
                          .filter(Boolean)
                          .join(" · ")
                      : "SIN VÍNCULO SOCIETARIO"}
                  </small>
                </div>

                <div
                  className="global-table-cell is-center personas-date-cell"
                  role="cell"
                >
                  <strong>{formatDate(relevantDate)}</strong>
                  <small title={relevantDateLabel}>{relevantDateLabel}</small>
                </div>

                <div
                  className="global-table-cell global-table-cell--actions"
                  role="cell"
                >
                  <div className="global-table-actions personas-table-actions">
                    <button
                      aria-label={`Ver información de ${item.nombre_exhibicion}`}
                      className="persona-action-button is-info"
                      onClick={() => openExisting(item, "view")}
                      title="Ver información"
                      type="button"
                    >
                      <GlobalActionIcon name="info" size={12} />
                    </button>
                    {canManage ? (
                      <>
                        <button
                          aria-label={`Editar ficha de ${item.nombre_exhibicion}`}
                          className="persona-action-button is-edit"
                          onClick={() => openExisting(item, "edit")}
                          title="Editar ficha"
                          type="button"
                        >
                          <GlobalActionIcon name="edit" size={12} />
                        </button>
                        <button
                          aria-label={
                            active
                              ? `Dar de baja a ${item.nombre_exhibicion}`
                              : `Reactivar a ${item.nombre_exhibicion}`
                          }
                          className={`persona-action-button ${
                            active ? "is-warning" : "is-success"
                          }`}
                          onClick={() => openStateModal(item)}
                          title={active ? "Dar de baja" : "Reactivar"}
                          type="button"
                        >
                          <GlobalActionIcon
                            name={active ? "userSlash" : "userCheck"}
                            size={12}
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
          onClearError={clearFormError}
          onClose={closeModal}
          onSave={savePerson}
          onValidationError={showValidationErrors}
          saving={saving}
        />
      ) : null}

      <ModalEliminarGlobal
        confirmDisabled={
          isTrue(stateModal?.activo) &&
          (stateImpactLoading ||
            !stateDate ||
            stateDate > localDateValue() ||
            (Number(stateImpact.total || 0) > 0 && !stateImpactConfirmed))
        }
        confirmLabel={isTrue(stateModal?.activo) ? "Dar de baja" : "Reactivar"}
        details={[
          {
            label: "Persona",
            value: stateModal?.nombre_exhibicion || "—",
          },
          {
            label: "Documento",
            value: stateModal?.dni || stateModal?.cuit_cuil || "—",
          },
          {
            label: "Estado actual",
            value: isTrue(stateModal?.activo) ? "ACTIVA" : "BAJA",
          },
        ]}
        errorMessage="No se pudo actualizar el estado de la persona."
        extraContent={
          isTrue(stateModal?.activo) ? (
            <div className="persona-state-extra">
              <label className="persona-state-date">
                <span>Fecha de baja *</span>
                <input
                  max={localDateValue()}
                  onClick={(event) => {
                    try {
                      event.currentTarget.showPicker?.();
                    } catch (_) {
                      // El navegador puede bloquear showPicker fuera de un gesto directo.
                    }
                  }}
                  onChange={(event) => setStateDate(event.target.value)}
                  type="date"
                  value={stateDate}
                />
              </label>

              <div
                className={`persona-link-impact ${
                  stateImpactLoading
                    ? "is-loading"
                    : Number(stateImpact.total || 0) > 0
                      ? "has-links"
                      : "is-clear"
                }`}
              >
                {stateImpactLoading ? (
                  <div className="persona-link-impact__status">
                    <GlobalIcon className="is-spinning" name="loader" size={19} />
                    <div>
                      <strong>Verificando vínculos activos...</strong>
                      <span>
                        No se habilitará la baja hasta finalizar el control.
                      </span>
                    </div>
                  </div>
                ) : Number(stateImpact.total || 0) > 0 ? (
                  <>
                    <div className="persona-link-impact__status">
                      <GlobalIcon name="warning" size={20} />
                      <div>
                        <strong>
                          {Number(stateImpact.total || 0) === 1
                            ? "Esta persona tiene 1 vínculo activo"
                            : `Esta persona tiene ${stateImpact.total} vínculos activos`}
                        </strong>
                        <span>
                          Al confirmar la baja, se marcarán como inactivos con la
                          misma fecha. No se eliminarán los registros.
                        </span>
                      </div>
                    </div>
                    <div className="persona-link-impact__summary">
                      <span>Como titular: {stateImpact.como_titular || 0}</span>
                      <span>
                        Como vinculada: {stateImpact.como_vinculada || 0}
                      </span>
                    </div>
                    <ul className="persona-link-impact__list">
                      {stateImpact.items.map((link) => (
                        <li key={`${link.id_vinculo}-${link.rol_persona}`}>
                          <div>
                            <strong>
                              {link.nombre_otra_persona || "PERSONA SIN NOMBRE"}
                            </strong>
                            <span>
                              {link.documento_otra_persona || "SIN DOCUMENTO"}
                            </span>
                          </div>
                          <small>
                            {linkTypeLabel(link.tipo_vinculo)} · ROL DE ESTA
                            PERSONA: {link.rol_persona}
                          </small>
                        </li>
                      ))}
                    </ul>
                    <label className="persona-link-impact__confirmation">
                      <input
                        checked={stateImpactConfirmed}
                        onChange={(event) =>
                          setStateImpactConfirmed(event.target.checked)
                        }
                        type="checkbox"
                      />
                      <span>
                        Revisé los vínculos y confirmo que deben cerrarse junto
                        con la baja de la persona.
                      </span>
                    </label>
                  </>
                ) : (
                  <div className="persona-link-impact__status">
                    <GlobalIcon name="check" size={20} />
                    <div>
                      <strong>Sin vínculos activos</strong>
                      <span>
                        La baja no dejará autorizaciones o beneficiarios vigentes.
                      </span>
                    </div>
                  </div>
                )}
              </div>
            </div>
          ) : null
        }
        loading={changingStatus}
        loadingLabel={
          isTrue(stateModal?.activo) ? "Dando de baja..." : "Reactivando..."
        }
        loadingMessage={
          isTrue(stateModal?.activo)
            ? "Dando de baja a la persona…"
            : "Reactivando a la persona…"
        }
        message={
          isTrue(stateModal?.activo)
            ? "La persona dejará de figurar como activa. Sus datos se conservarán y los vínculos activos se cerrarán sin eliminarse."
            : "La persona volverá a estar disponible para nuevas operaciones."
        }
        modalClassName="persona-state-global-modal"
        onConfirm={confirmStatusChange}
        onClose={closeStateModal}
        onToast={(typeValue, messageValue, durationValue) =>
          setFeedback({
            duration: durationValue,
            message: messageValue,
            type:
              typeValue === "exito"
                ? "success"
                : typeValue === "advertencia"
                  ? "warning"
                  : typeValue === "cargando"
                    ? "loading"
                    : typeValue,
          })
        }
        open={Boolean(stateModal)}
        operacion={isTrue(stateModal?.activo) ? "baja" : "alta"}
        reasonLabel="Motivo de baja *"
        reasonPlaceholder="INDICÁ EL MOTIVO DE LA BAJA..."
        reasonRequired={isTrue(stateModal?.activo)}
        row={stateModal}
        showReason={isTrue(stateModal?.activo)}
        successMessage={
          isTrue(stateModal?.activo)
            ? "Persona dada de baja correctamente."
            : "Persona reactivada correctamente."
        }
        title={
          isTrue(stateModal?.activo)
            ? "Dar de baja a la persona"
            : "Reactivar persona"
        }
      />
    </>
  );
}
