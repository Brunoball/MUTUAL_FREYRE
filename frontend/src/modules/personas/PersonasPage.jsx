import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { MODULE_CATALOG } from "../../config/moduleCatalog";
import GlobalDivTable from "../../Global/components/GlobalDivTable";
import GlobalIcon from "../../Global/components/GlobalIcon";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { ModulePage } from "../../Global/components/ModulePage";
import { getPersonasStructure } from "./personas.api";
import "./Personas.css";

const normalizeText = (value) =>
  String(value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es-AR")
    .trim();

const firstValue = (record, fields, fallback = "") => {
  for (const field of fields) {
    const value = record?.[field];
    if (value !== undefined && value !== null && String(value).trim() !== "") {
      return value;
    }
  }
  return fallback;
};

const readCollection = (response) => {
  if (Array.isArray(response)) return response;

  const directCandidates = [
    response?.items,
    response?.personas,
    response?.registros,
    response?.results,
    response?.data,
  ];

  for (const candidate of directCandidates) {
    if (Array.isArray(candidate)) return candidate;
    if (Array.isArray(candidate?.items)) return candidate.items;
    if (Array.isArray(candidate?.personas)) return candidate.personas;
    if (Array.isArray(candidate?.registros)) return candidate.registros;
  }

  return [];
};

const activeFrom = (record) => {
  const value = firstValue(record, ["activo", "activa", "habilitado", "estado"], true);
  if (typeof value === "boolean") return value;
  if (typeof value === "number") return value !== 0;

  return ![
    "0",
    "false",
    "baja",
    "inactivo",
    "inactiva",
    "deshabilitado",
    "deshabilitada",
  ].includes(normalizeText(value));
};

const formatDate = (value) => {
  if (!value) return "—";
  const datePart = String(value).slice(0, 10);
  const date = new Date(`${datePart}T00:00:00`);
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat("es-AR").format(date);
};

const personName = (record) => {
  const fullName = firstValue(record, [
    "nombre_completo",
    "nombreCompleto",
    "denominacion",
    "razon_social",
    "razonSocial",
  ]);
  if (fullName) return String(fullName);

  const firstName = firstValue(record, ["nombre", "nombres"]);
  const lastName = firstValue(record, ["apellido", "apellidos"]);
  if (firstName && lastName) return `${lastName}, ${firstName}`;
  return String(lastName || firstName || "PERSONA SIN NOMBRE");
};

const personView = (record, index) => {
  const phone = firstValue(record, ["telefono", "celular", "telefono_movil", "movil"]);
  const email = firstValue(record, ["email", "correo", "correo_electronico"]);
  const address = firstValue(record, ["domicilio", "direccion", "direccion_completa"]);
  const locality = firstValue(record, ["localidad", "ciudad", "municipio"]);

  return {
    raw: record,
    key: firstValue(record, ["id_persona", "persona_id", "id", "uuid"], `persona-${index}`),
    name: personName(record),
    secondary: firstValue(record, ["alias", "nombre_fantasia", "nombreFantasia"]),
    document: firstValue(record, ["dni", "documento", "cuit", "cuil", "nro_documento"], "—"),
    phone: phone || "—",
    email,
    address: address || "—",
    locality,
    type: firstValue(record, ["tipo_persona", "tipoPersona", "tipo", "rol", "categoria"], "GENERAL"),
    registeredAt: firstValue(record, ["fecha_alta", "fechaAlta", "created_at", "fecha_registro"]),
    active: activeFrom(record),
  };
};

const uniqueOptions = (values) =>
  [...new Set(values.filter(Boolean).map((value) => String(value).trim()))]
    .sort((a, b) => a.localeCompare(b, "es-AR"))
    .map((value) => ({ value, label: value }));

export default function PersonasPage({
  canManage = true,
  onCreate,
  onView,
  onEdit,
  onToggleStatus,
}) {
  const moduleConfig = MODULE_CATALOG.personas || {};
  const requestId = useRef(0);
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [feedback, setFeedback] = useState(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("active");
  const [type, setType] = useState("");
  const [locality, setLocality] = useState("");

  const load = useCallback(async ({ notify = false } = {}) => {
    const currentRequest = ++requestId.current;
    setLoading(true);
    setError("");

    try {
      const response = await getPersonasStructure();
      if (currentRequest !== requestId.current) return;
      if (response?.success === false) {
        throw new Error(response.message || response.mensaje || "No se pudieron cargar las personas.");
      }

      setRecords(readCollection(response));
      if (notify) {
        setFeedback({ type: "success", message: "Listado actualizado correctamente." });
      }
    } catch (loadError) {
      if (currentRequest !== requestId.current) return;
      const message = loadError?.message || "No se pudieron cargar las personas.";
      setError(message);
      setFeedback({ type: "error", message });
    } finally {
      if (currentRequest === requestId.current) setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    return () => {
      requestId.current += 1;
    };
  }, [load]);

  const people = useMemo(
    () => records.map(personView).sort((a, b) => a.name.localeCompare(b.name, "es-AR")),
    [records],
  );
  const typeOptions = useMemo(() => uniqueOptions(people.map((item) => item.type)), [people]);
  const localityOptions = useMemo(
    () => uniqueOptions(people.map((item) => item.locality)),
    [people],
  );

  const visiblePeople = useMemo(() => {
    const query = normalizeText(search);

    return people.filter((item) => {
      if (status === "active" && !item.active) return false;
      if (status === "inactive" && item.active) return false;
      if (type && item.type !== type) return false;
      if (locality && item.locality !== locality) return false;
      if (!query) return true;

      return normalizeText(
        [
          item.name,
          item.secondary,
          item.document,
          item.phone,
          item.email,
          item.address,
          item.locality,
          item.type,
        ].join(" "),
      ).includes(query);
    });
  }, [locality, people, search, status, type]);

  const missingIntegration = (label) => {
    setFeedback({
      type: "info",
      message: `${label} quedó preparado para conectarse con la pantalla o modal existente.`,
    });
  };

  const runSimpleAction = (handler, item, label) => {
    if (typeof handler !== "function") {
      missingIntegration(label);
      return;
    }

    try {
      handler(item?.raw ?? item);
    } catch (actionError) {
      setFeedback({
        type: "error",
        message: actionError?.message || `No se pudo completar la acción: ${label.toLocaleLowerCase("es-AR")}.`,
      });
    }
  };

  const changeStatus = async (item) => {
    if (typeof onToggleStatus !== "function") {
      missingIntegration(item.active ? "Dar de baja" : "Reactivar");
      return;
    }

    let actionResult;
    try {
      actionResult = onToggleStatus(item.raw, !item.active);
    } catch (actionError) {
      setFeedback({
        type: "error",
        message: actionError?.message || "No se pudo actualizar el estado de la persona.",
      });
      return;
    }

    // Si el callback solo abre el modal propio del proyecto, no se reemplaza
    // su flujo ni se muestra un éxito anticipado.
    if (!actionResult || typeof actionResult.then !== "function") return;

    setFeedback({
      type: "loading",
      message: item.active ? "Dando de baja a la persona…" : "Reactivando a la persona…",
    });

    try {
      await actionResult;
      await load();
      setFeedback({
        type: "success",
        message: item.active
          ? "Persona dada de baja correctamente."
          : "Persona reactivada correctamente.",
      });
    } catch (actionError) {
      setFeedback({
        type: "error",
        message: actionError?.message || "No se pudo actualizar el estado de la persona.",
      });
    }
  };

  const filters = [
    {
      key: "status",
      label: "Estado",
      type: "tabs",
      ariaLabel: "Estado de las personas",
      value: status,
      onChange: setStatus,
      options: [
        { value: "active", label: "Activas" },
        { value: "inactive", label: "Bajas" },
      ],
    },
    {
      key: "search",
      label: "Búsqueda",
      type: "search",
      placeholder: " ",
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
      options: typeOptions,
    },
    {
      key: "locality",
      label: "Localidad",
      type: "select",
      placeholder: "Todas",
      value: locality,
      onChange: setLocality,
      options: localityOptions,
    },
  ];

  return (
    <>
      <ModulePage
        canCreate={canManage}
        filters={filters}
        onPrimaryAction={() => runSimpleAction(onCreate, null, "Nueva persona")}
        onRefresh={() => load({ notify: true })}
        primaryActionLabel="Nueva persona"
        refreshing={loading && records.length > 0}
        tabsInTitle
        title={moduleConfig.title || moduleConfig.label || "Personas"}
      >
        <ModuleFeedback
          duration={feedback?.duration}
          message={feedback?.message}
          onClose={() => setFeedback(null)}
          type={feedback?.type}
        />

        <GlobalDivTable
          ariaLabel="Listado de personas"
          bodyClassName="personas-global-table__body"
          className="personas-global-table"
          columns={[
            "Persona",
            "Documento",
            "Contacto",
            "Domicilio",
            "Tipo",
            "Alta",
            "Estado",
            "Acciones",
          ]}
          gridClassName="personas-global-grid"
        >
          {loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon className="is-spinning" name="loader" size={28} />
              <strong>Cargando personas...</strong>
              <span>Consultando el padrón del sistema.</span>
            </div>
          ) : null}

          {!loading && !error && !visiblePeople.length ? (
            <div className="global-table-empty">
              <GlobalIcon name="inbox" size={30} />
              <strong>Sin personas para mostrar</strong>
              <span>Creá el primer registro o cambiá los filtros aplicados.</span>
            </div>
          ) : null}

          {!loading && error && !records.length ? (
            <div className="global-table-empty is-error">
              <GlobalIcon name="error" size={30} />
              <strong>No se pudo cargar el listado</strong>
              <span>{error}</span>
            </div>
          ) : null}

          {visiblePeople.map((item) => (
            <div
              className="global-div-table__row personas-global-grid"
              key={item.key}
              role="row"
            >
              <div className="global-table-cell global-table-cell--main" role="cell">
                <strong>{item.name}</strong>
                {item.secondary ? <small>{item.secondary}</small> : null}
              </div>
              <div className="global-table-cell is-strong is-center" role="cell">
                {item.document}
              </div>
              <div className="global-table-cell global-table-cell--main" role="cell">
                <span>{item.phone}</span>
                {item.email ? <small>{item.email}</small> : null}
              </div>
              <div className="global-table-cell global-table-cell--main" role="cell">
                <span>{item.address}</span>
                {item.locality ? <small>{item.locality}</small> : null}
              </div>
              <div className="global-table-cell" role="cell">
                <span className="global-table-wrap-text">{item.type}</span>
              </div>
              <div className="global-table-cell is-center" role="cell">
                {formatDate(item.registeredAt)}
              </div>
              <div className="global-table-cell is-center" role="cell">
                <span className={`global-chip ${item.active ? "is-success" : "is-danger"}`}>
                  {item.active ? "ACTIVA" : "BAJA"}
                </span>
              </div>
              <div className="global-table-cell global-table-cell--actions" role="cell">
                <div className="global-table-actions">
                  <button
                    aria-label={`Ver ${item.name}`}
                    className="global-icon-button"
                    onClick={() => runSimpleAction(onView, item, "Ver persona")}
                    title="Ver información"
                    type="button"
                  >
                    <GlobalIcon name="eye" size={15} />
                  </button>
                  {canManage ? (
                    <>
                      <button
                        aria-label={`Editar ${item.name}`}
                        className="global-icon-button"
                        onClick={() => runSimpleAction(onEdit, item, "Editar persona")}
                        title="Editar"
                        type="button"
                      >
                        <GlobalIcon name="edit" size={15} />
                      </button>
                      <button
                        aria-label={`${item.active ? "Dar de baja" : "Reactivar"} ${item.name}`}
                        className={`global-icon-button ${item.active ? "is-danger" : "is-success"}`}
                        onClick={() => changeStatus(item)}
                        title={item.active ? "Dar de baja" : "Reactivar"}
                        type="button"
                      >
                        <GlobalIcon name={item.active ? "disable" : "enable"} size={16} />
                      </button>
                    </>
                  ) : null}
                </div>
              </div>
            </div>
          ))}
        </GlobalDivTable>
      </ModulePage>
    </>
  );
}
