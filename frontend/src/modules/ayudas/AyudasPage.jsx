import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { MODULE_CATALOG } from "../../config/moduleCatalog";
import GlobalDivTable from "../../Global/components/GlobalDivTable";
import GlobalIcon from "../../Global/components/GlobalIcon";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { ModulePage } from "../../Global/components/ModulePage";
import { getAyudasStructure } from "./ayudas.api";
import "./Ayudas.css";

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

const displayValue = (value, fallback = "") => {
  if (value === undefined || value === null || value === "") return fallback;
  if (typeof value !== "object") return String(value);
  return String(
    firstValue(value, ["nombre", "label", "descripcion", "detalle", "titulo"], fallback),
  );
};

const readCollection = (response) => {
  if (Array.isArray(response)) return response;

  const candidates = [
    response?.items,
    response?.ayudas,
    response?.registros,
    response?.results,
    response?.data,
  ];

  for (const candidate of candidates) {
    if (Array.isArray(candidate)) return candidate;
    if (Array.isArray(candidate?.items)) return candidate.items;
    if (Array.isArray(candidate?.ayudas)) return candidate.ayudas;
    if (Array.isArray(candidate?.registros)) return candidate.registros;
  }

  return [];
};

const formatDate = (value) => {
  if (!value) return "—";
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat("es-AR").format(date);
};

const numericValue = (value) => {
  if (typeof value === "number") return value;
  const source = String(value ?? "").trim();
  if (!source) return null;

  const normalized = source.includes(",")
    ? source.replace(/\./g, "").replace(",", ".")
    : source;
  const number = Number(normalized.replace(/[^0-9.-]/g, ""));
  return Number.isFinite(number) ? number : null;
};

const formatMoney = (value) => {
  const amount = numericValue(value);
  if (amount === null) return "—";
  return new Intl.NumberFormat("es-AR", {
    style: "currency",
    currency: "ARS",
    minimumFractionDigits: 2,
  }).format(amount);
};

const personName = (record) => {
  const person = firstValue(record, ["beneficiario", "persona", "socio", "solicitante"]);
  if (typeof person === "string") return person;
  if (person && typeof person === "object") {
    const complete = firstValue(person, ["nombre_completo", "nombreCompleto", "denominacion"]);
    if (complete) return String(complete);
    const firstName = firstValue(person, ["nombre", "nombres"]);
    const lastName = firstValue(person, ["apellido", "apellidos"]);
    if (firstName && lastName) return `${lastName}, ${firstName}`;
    if (firstName || lastName) return String(lastName || firstName);
  }

  const complete = firstValue(record, [
    "beneficiario_nombre",
    "persona_nombre",
    "nombre_completo",
    "nombreCompleto",
  ]);
  if (complete) return String(complete);

  const firstName = firstValue(record, ["nombre", "nombres"]);
  const lastName = firstValue(record, ["apellido", "apellidos"]);
  if (firstName && lastName) return `${lastName}, ${firstName}`;
  return String(lastName || firstName || "BENEFICIARIO SIN INFORMAR");
};

const personDocument = (record) => {
  const person = firstValue(record, ["beneficiario", "persona", "socio", "solicitante"]);
  return String(
    firstValue(
      record,
      ["dni", "documento", "cuit", "cuil", "nro_documento"],
      person && typeof person === "object"
        ? firstValue(person, ["dni", "documento", "cuit", "cuil", "nro_documento"], "")
        : "",
    ) || "—",
  );
};

const isOpenHelp = (record, status) => {
  const explicitField = ["activo", "activa", "vigente", "habilitado"].find(
    (field) => record?.[field] !== undefined && record?.[field] !== null,
  );

  if (explicitField) {
    const explicit = record[explicitField];
    if (typeof explicit === "boolean") return explicit;
    if (typeof explicit === "number") return explicit !== 0;
    return !["0", "false", "no", "inactivo", "inactiva", "baja"].includes(
      normalizeText(explicit),
    );
  }

  return ![
    "finalizada",
    "finalizado",
    "entregada",
    "entregado",
    "rechazada",
    "rechazado",
    "cancelada",
    "cancelado",
    "anulada",
    "anulado",
    "baja",
    "cerrada",
    "cerrado",
  ].includes(normalizeText(status));
};

const statusTone = (status) => {
  const normalized = normalizeText(status);
  if (["rechazada", "rechazado", "cancelada", "cancelado", "anulada", "anulado", "baja"].includes(normalized)) {
    return "is-danger";
  }
  if (["pendiente", "en revision", "en evaluacion", "solicitada", "solicitado"].includes(normalized)) {
    return "is-warning";
  }
  if (["aprobada", "aprobado", "entregada", "entregado", "activa", "activo", "vigente"].includes(normalized)) {
    return "is-success";
  }
  return "is-neutral";
};

const helpView = (record, index) => {
  const type = displayValue(
    firstValue(record, ["tipo_ayuda", "tipoAyuda", "tipo", "categoria", "concepto"]),
    "GENERAL",
  );
  const status = displayValue(
    firstValue(record, ["estado", "estado_ayuda", "situacion"]),
    "ACTIVA",
  );
  const responsible = displayValue(
    firstValue(record, ["responsable", "profesional", "usuario", "creado_por", "operador"]),
    "—",
  );

  return {
    raw: record,
    key: firstValue(record, ["id_ayuda", "ayuda_id", "id", "uuid"], `ayuda-${index}`),
    beneficiary: personName(record),
    document: personDocument(record),
    type,
    reason: String(firstValue(record, ["motivo", "detalle", "descripcion", "observaciones"], "SIN DETALLE")),
    date: firstValue(record, ["fecha_ayuda", "fecha_solicitud", "fecha", "created_at", "fecha_alta"]),
    amount: firstValue(record, ["monto", "importe", "total", "valor"], null),
    status,
    statusTone: statusTone(status),
    responsible,
    open: isOpenHelp(record, status),
  };
};

const uniqueOptions = (values) =>
  [...new Set(values.filter(Boolean).map((value) => String(value).trim()))]
    .sort((a, b) => a.localeCompare(b, "es-AR"))
    .map((value) => ({ value, label: value }));

export default function AyudasPage({
  canManage = true,
  onCreate,
  onView,
  onEdit,
  onToggleStatus,
}) {
  const moduleConfig = MODULE_CATALOG.ayudas || {};
  const requestId = useRef(0);
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [feedback, setFeedback] = useState(null);
  const [search, setSearch] = useState("");
  const [period, setPeriod] = useState("open");
  const [type, setType] = useState("");
  const [status, setStatus] = useState("");

  const load = useCallback(async ({ notify = false } = {}) => {
    const currentRequest = ++requestId.current;
    setLoading(true);
    setError("");

    try {
      const response = await getAyudasStructure();
      if (currentRequest !== requestId.current) return;
      if (response?.success === false) {
        throw new Error(response.message || response.mensaje || "No se pudieron cargar las ayudas.");
      }

      setRecords(readCollection(response));
      if (notify) {
        setFeedback({ type: "success", message: "Listado actualizado correctamente." });
      }
    } catch (loadError) {
      if (currentRequest !== requestId.current) return;
      const message = loadError?.message || "No se pudieron cargar las ayudas.";
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

  const helps = useMemo(
    () =>
      records
        .map(helpView)
        .sort((a, b) => String(b.date || "").localeCompare(String(a.date || ""))),
    [records],
  );
  const typeOptions = useMemo(() => uniqueOptions(helps.map((item) => item.type)), [helps]);
  const statusOptions = useMemo(
    () => uniqueOptions(helps.map((item) => item.status)),
    [helps],
  );

  const visibleHelps = useMemo(() => {
    const query = normalizeText(search);

    return helps.filter((item) => {
      if (period === "open" && !item.open) return false;
      if (period === "closed" && item.open) return false;
      if (type && item.type !== type) return false;
      if (status && item.status !== status) return false;
      if (!query) return true;

      return normalizeText(
        [
          item.beneficiary,
          item.document,
          item.type,
          item.reason,
          item.status,
          item.responsible,
        ].join(" "),
      ).includes(query);
    });
  }, [helps, period, search, status, type]);

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
      missingIntegration(item.open ? "Finalizar ayuda" : "Reabrir ayuda");
      return;
    }

    let actionResult;
    try {
      actionResult = onToggleStatus(item.raw, !item.open);
    } catch (actionError) {
      setFeedback({
        type: "error",
        message: actionError?.message || "No se pudo actualizar la ayuda.",
      });
      return;
    }

    // Un callback sin promesa puede abrir el modal propio sin que esta tabla
    // reemplace su flujo ni muestre un éxito anticipado.
    if (!actionResult || typeof actionResult.then !== "function") return;

    setFeedback({
      type: "loading",
      message: item.open ? "Finalizando la ayuda…" : "Reabriendo la ayuda…",
    });

    try {
      await actionResult;
      await load();
      setFeedback({
        type: "success",
        message: item.open
          ? "Ayuda finalizada correctamente."
          : "Ayuda reabierta correctamente.",
      });
    } catch (actionError) {
      setFeedback({
        type: "error",
        message: actionError?.message || "No se pudo actualizar la ayuda.",
      });
    }
  };

  const filters = [
    {
      key: "period",
      label: "Vigencia",
      type: "tabs",
      ariaLabel: "Vigencia de las ayudas",
      value: period,
      onChange: setPeriod,
      options: [
        { value: "open", label: "Vigentes" },
        { value: "closed", label: "Cerradas" },
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
      key: "status",
      label: "Estado",
      type: "select",
      placeholder: "Todos",
      value: status,
      onChange: setStatus,
      options: statusOptions,
    },
  ];

  return (
    <>
      <ModulePage
        canCreate={canManage}
        filters={filters}
        onPrimaryAction={() => runSimpleAction(onCreate, null, "Nueva ayuda")}
        onRefresh={() => load({ notify: true })}
        primaryActionLabel="Nueva ayuda"
        refreshing={loading && records.length > 0}
        tabsInTitle
        title={moduleConfig.title || moduleConfig.label || "Ayudas"}
      >
        <ModuleFeedback
          duration={feedback?.duration}
          message={feedback?.message}
          onClose={() => setFeedback(null)}
          type={feedback?.type}
        />

        <GlobalDivTable
          ariaLabel="Listado de ayudas"
          bodyClassName="ayudas-global-table__body"
          className="ayudas-global-table"
          columns={[
            "Beneficiario",
            "Tipo de ayuda",
            "Motivo",
            "Fecha",
            "Monto",
            "Estado",
            "Responsable",
            "Acciones",
          ]}
          gridClassName="ayudas-global-grid"
        >
          {loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon className="is-spinning" name="loader" size={28} />
              <strong>Cargando ayudas...</strong>
              <span>Consultando los registros del sistema.</span>
            </div>
          ) : null}

          {!loading && !error && !visibleHelps.length ? (
            <div className="global-table-empty">
              <GlobalIcon name="inbox" size={30} />
              <strong>Sin ayudas para mostrar</strong>
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

          {visibleHelps.map((item) => (
            <div
              className="global-div-table__row ayudas-global-grid"
              key={item.key}
              role="row"
            >
              <div className="global-table-cell global-table-cell--main" role="cell">
                <strong>{item.beneficiary}</strong>
                <small>{item.document}</small>
              </div>
              <div className="global-table-cell" role="cell">
                <span className="global-table-wrap-text">{item.type}</span>
              </div>
              <div className="global-table-cell" role="cell">
                <span className="global-table-wrap-text">{item.reason}</span>
              </div>
              <div className="global-table-cell is-center" role="cell">
                {formatDate(item.date)}
              </div>
              <div className="global-table-cell is-right is-strong" role="cell">
                {formatMoney(item.amount)}
              </div>
              <div className="global-table-cell is-center" role="cell">
                <span className={`global-chip ${item.statusTone}`}>{item.status}</span>
              </div>
              <div className="global-table-cell" role="cell">
                <span className="global-table-wrap-text">{item.responsible}</span>
              </div>
              <div className="global-table-cell global-table-cell--actions" role="cell">
                <div className="global-table-actions">
                  <button
                    aria-label={`Ver ayuda de ${item.beneficiary}`}
                    className="global-icon-button"
                    onClick={() => runSimpleAction(onView, item, "Ver ayuda")}
                    title="Ver información"
                    type="button"
                  >
                    <GlobalIcon name="eye" size={15} />
                  </button>
                  {canManage ? (
                    <>
                      <button
                        aria-label={`Editar ayuda de ${item.beneficiary}`}
                        className="global-icon-button"
                        onClick={() => runSimpleAction(onEdit, item, "Editar ayuda")}
                        title="Editar"
                        type="button"
                      >
                        <GlobalIcon name="edit" size={15} />
                      </button>
                      <button
                        aria-label={`${item.open ? "Finalizar" : "Reabrir"} ayuda de ${item.beneficiary}`}
                        className={`global-icon-button ${item.open ? "is-danger" : "is-success"}`}
                        onClick={() => changeStatus(item)}
                        title={item.open ? "Finalizar" : "Reabrir"}
                        type="button"
                      >
                        <GlobalIcon name={item.open ? "disable" : "enable"} size={16} />
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
