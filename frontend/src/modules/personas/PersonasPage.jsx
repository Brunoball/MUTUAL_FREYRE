import React, { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "../../app/AuthProvider";
import {
  catalogToOptions,
  GLOBAL_OPTIONS,
  toUpperText,
  uppercaseDeep,
} from "../../config/globalOptions";
import { EMPTY_PERSONAS_CATALOGS, normalizePersonasCatalogs } from "../../config/globalCatalogs";
import { MODULE_CATALOG } from "../../config/moduleCatalog";
import GlobalDivTable from "../../Global/components/GlobalDivTable";
import GlobalIcon from "../../Global/components/GlobalIcon";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { ModulePage } from "../../Global/components/ModulePage";

import { getPersonasStructure } from "./personas.api";

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
const typeLabel = (value) => (value === "JURIDICA" ? "JURÍDICA" : "FÍSICA");


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

  const loadCatalogs = useCallback(async () => {
    setCatalogsLoading(true);
    try {
      const response = await getPersonasCatalogos();
      setCatalogs(normalizePersonasCatalogs(response));
    } catch (error) {
      setFeedback({
        type: "error",
        message: toUpperText(error?.message || "NO SE PUDIERON CARGAR LOS SELECTORES DEL SISTEMA."),
      });
    } finally {
      setCatalogsLoading(false);
    }
  }, []);

  const load = useCallback(async ({ notify = false, query = search } = {}) => {
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
      if (notify) setFeedback({ type: "success", message: "LISTADO ACTUALIZADO CORRECTAMENTE." });
    } catch (error) {
      if (requestId !== requestRef.current) return;
      setFeedback({ type: "error", message: toUpperText(error?.message || "NO SE PUDO CARGAR EL PADRÓN.") });
    } finally {
      if (requestId === requestRef.current) setLoading(false);
    }
  }, [localityId, membership, search, status, type]);

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
      setFeedback({ type: "info", message: "ESPERÁ UN MOMENTO: SE ESTÁN CARGANDO LOS SELECTORES DEL SISTEMA." });
      return;
    }
    if (!catalogs.paises.length) {
      setFeedback({ type: "error", message: "NO SE PUEDE ABRIR EL ALTA PORQUE NO SE CARGARON LOS SELECTORES DE LA BASE DE DATOS." });
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
      setFeedback({ type: "error", message: toUpperText(error?.message || "NO SE PUDO ABRIR EL LEGAJO.") });
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
        await crearPersona(uppercaseDeep(payload));
        setFeedback({ type: "success", message: "LA PERSONA FUE CREADA CORRECTAMENTE." });
      } else {
        await actualizarPersona(modal?.detail?.persona?.id_persona, uppercaseDeep(payload));
        setFeedback({ type: "success", message: "EL LEGAJO FUE ACTUALIZADO CORRECTAMENTE." });
      }
      setModal(null);
      await Promise.all([load({ query: search }), loadCatalogs()]);
    } catch (error) {
      setFormErrors(error?.fields || {});
      setFeedback({ type: "error", message: toUpperText(error?.message || "NO SE PUDO GUARDAR EL LEGAJO.") });
    } finally {
      setSaving(false);
    }
  };

  const toggleStatus = async (item) => {
    const currentlyActive = isTrue(item.activo);
    let reason = "";
    if (currentlyActive) {
      reason = window.prompt(`MOTIVO DE BAJA DE ${toUpperText(item.nombre_exhibicion)}:`, "") ?? "";
      if (!reason.trim()) return;
    } else if (!window.confirm(`¿REACTIVAR A ${toUpperText(item.nombre_exhibicion)}?`)) {
      return;
    }

    try {
      await cambiarEstadoPersona(item.id_persona, !currentlyActive, toUpperText(reason.trim()));
      setFeedback({
        type: "success",
        message: currentlyActive ? "PERSONA DADA DE BAJA CORRECTAMENTE." : "PERSONA REACTIVADA CORRECTAMENTE.",
      });
      await load({ query: search });
    } catch (error) {
      setFeedback({ type: "error", message: toUpperText(error?.message || "NO SE PUDO ACTUALIZAR EL ESTADO.") });
    }
  };

  const filters = [
    {
      key: "status",
      label: "ESTADO",
      type: "tabs",
      value: status,
      onChange: setStatus,
      options: GLOBAL_OPTIONS.statusFilters.filter((option) => option.value !== "TODAS"),
    },
    {
      key: "search",
      label: "BÚSQUEDA",
      type: "search",
      placeholder: "NOMBRE, DNI, CUIT O N.º DE SOCIO",
      value: search,
      onChange: (value) => setSearch(toUpperText(value)),
    },
    {
      key: "type",
      label: "TIPO",
      type: "select",
      placeholder: "TODOS",
      value: type,
      onChange: setType,
      options: GLOBAL_OPTIONS.personTypes,
    },
    {
      key: "membership",
      label: "ASOCIACIÓN",
      type: "select",
      placeholder: "TODAS",
      value: membership,
      onChange: setMembership,
      options: GLOBAL_OPTIONS.membershipFilters,
    },
    {
      key: "locality",
      label: "LOCALIDAD",
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
        primaryActionLabel="NUEVA PERSONA"
        tabsInTitle
        title={toUpperText(moduleConfig.title || "PERSONAS Y ASOCIADOS")}
      >
        <ModuleFeedback
          duration={feedback?.duration}
          message={feedback?.message}
          onClose={() => setFeedback(null)}
          type={feedback?.type}
        />

        <GlobalDivTable
          ariaLabel="LISTADO DE PERSONAS Y ASOCIADOS"
          bodyClassName="personas-global-table__body"
          className="personas-global-table"
          columns={["SOCIO", "PERSONA", "DOCUMENTO", "TIPO", "LOCALIDAD", "ESTADO", "ACCIONES"]}
          gridClassName="personas-global-grid"
        >
          {loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon className="is-spinning" name="loader" size={28} />
              <strong>CARGANDO PADRÓN...</strong>
              <span>CONSULTANDO PERSONAS FÍSICAS, JURÍDICAS Y ASOCIADOS.</span>
            </div>
          ) : null}

          {!loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon name="inbox" size={30} />
              <strong>NO HAY REGISTROS PARA MOSTRAR</strong>
              <span>CREÁ LA PRIMERA PERSONA O MODIFICÁ LOS FILTROS.</span>
            </div>
          ) : null}

          {records.map((item) => {
            const active = isTrue(item.activo);
            return (
              <div className="global-div-table__row personas-global-grid" key={item.id_persona} role="row">
                <div className="global-table-cell is-center is-strong" role="cell">
                  {item.id_asociado ? `#${item.id_asociado}` : "—"}
                </div>
                <div className="global-table-cell global-table-cell--main" role="cell">
                  <strong>{toUpperText(item.nombre_exhibicion)}</strong>
                  <small>{item.id_asociado ? `${toUpperText(item.categoria || "SIN CATEGORÍA")} · ${toUpperText(item.estado_asociado)}` : "PERSONA NO ASOCIADA"}</small>
                </div>
                <div className="global-table-cell is-center is-strong" role="cell">
                  {item.dni || item.cuit_cuil || "—"}
                </div>
                <div className="global-table-cell is-center" role="cell">{typeLabel(item.tipo_persona)}</div>
                <div className="global-table-cell global-table-cell--main" role="cell">
                  <span>{toUpperText(item.localidad || "—")}</span>
                  {item.provincia ? <small>{toUpperText(item.provincia)}</small> : null}
                </div>
                <div className="global-table-cell is-center" role="cell">
                  <span className={`global-chip ${active ? "is-success" : "is-danger"}`}>{active ? "ACTIVA" : "BAJA"}</span>
                </div>
                <div className="global-table-cell global-table-cell--actions" role="cell">
                  <div className="global-table-actions">
                    <button className="global-icon-button" onClick={() => openExisting(item, "view")} title="VER FICHA" type="button">
                      <GlobalIcon name="eye" size={15} />
                    </button>
                    {canManage ? (
                      <>
                        <button className="global-icon-button" onClick={() => openExisting(item, "edit")} title="EDITAR" type="button">
                          <GlobalIcon name="edit" size={15} />
                        </button>
                        <button
                          className={`global-icon-button ${active ? "is-danger" : "is-success"}`}
                          onClick={() => toggleStatus(item)}
                          title={active ? "DAR DE BAJA" : "REACTIVAR"}
                          type="button"
                        >
                          <GlobalIcon name={active ? "disable" : "enable"} size={16} />
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
        <div className="persona-modal-backdrop">
          <div className="persona-modal-loading">
            <GlobalIcon className="is-spinning" name="loader" size={28} />
            <span>CARGANDO LEGAJO...</span>
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
    </>
  );
}
