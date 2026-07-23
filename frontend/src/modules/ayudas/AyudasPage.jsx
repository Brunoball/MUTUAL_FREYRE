import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "../../app/AuthProvider";
import { MODULE_CATALOG } from "../../config/moduleCatalog";
import GlobalDivTable from "../../Global/components/GlobalDivTable";
import GlobalIcon from "../../Global/components/GlobalIcon";
import ModuleFeedback from "../../Global/components/ModuleFeedback";
import { ModulePage } from "../../Global/components/ModulePage";
import AyudaAnulacionModal from "./AyudaAnulacionModal";
import AyudaDetalleModal from "./AyudaDetalleModal";
import AyudaModal from "./AyudaModal";
import AyudaParametrosModal from "./AyudaParametrosModal";
import AyudaRenovacionModal from "./AyudaRenovacionModal";
import {
  getAyudaDetalle,
  getAyudas,
  getAyudasCatalogos,
} from "./ayudas.api";
import {
  aidNumber,
  formatDate,
  formatMoney,
  statusLabel,
  statusTone,
  localDateValue,
} from "./ayudas.utils";
import "./Ayudas.css";

export default function AyudasPage() {
  const moduleConfig = MODULE_CATALOG.ayudas || {};
  const { can } = useAuth();
  const canManage = can("ayudas.manage");
  const requestRef = useRef(0);
  const searchTimerRef = useRef(null);

  const [records, setRecords] = useState([]);
  const [catalogs, setCatalogs] = useState({ productos: [], socios: [], garantes: [] });
  const [loading, setLoading] = useState(true);
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [feedback, setFeedback] = useState(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("VIGENTES");
  const [type, setType] = useState("");

  const [createOpen, setCreateOpen] = useState(false);
  const [parametersOpen, setParametersOpen] = useState(false);
  const [detail, setDetail] = useState(null);
  const [renewalDetail, setRenewalDetail] = useState(null);
  const [annulDetail, setAnnulDetail] = useState(null);
  const [openingDetail, setOpeningDetail] = useState(false);

  const loadCatalogs = useCallback(async () => {
    setCatalogLoading(true);
    try {
      const response = await getAyudasCatalogos();
      setCatalogs(response || { productos: [], socios: [], garantes: [] });
    } catch (error) {
      setFeedback({ type: "error", message: error?.message || "No se pudieron cargar los selectores de ayudas." });
    } finally {
      setCatalogLoading(false);
    }
  }, []);

  const load = useCallback(async ({ notify = false, query = search } = {}) => {
    const requestId = ++requestRef.current;
    setLoading(true);
    try {
      const response = await getAyudas({
        buscar: query,
        estado: status,
        tipo: type,
        limite: 400,
      });
      if (requestId !== requestRef.current) return;
      setRecords(Array.isArray(response?.items) ? response.items : []);
      if (notify) setFeedback({ type: "success", message: "Listado actualizado correctamente." });
    } catch (error) {
      if (requestId !== requestRef.current) return;
      setFeedback({ type: "error", message: error?.message || "No se pudieron cargar las ayudas económicas." });
    } finally {
      if (requestId === requestRef.current) setLoading(false);
    }
  }, [search, status, type]);

  useEffect(() => {
    loadCatalogs();
  }, [loadCatalogs]);

  useEffect(() => {
    clearTimeout(searchTimerRef.current);
    searchTimerRef.current = setTimeout(() => load({ query: search }), 220);
    return () => clearTimeout(searchTimerRef.current);
  }, [load, search]);

  const openCreate = () => {
    if (catalogLoading) {
      setFeedback({ type: "info", message: "Esperá un momento: se están cargando socios, tasas y parámetros." });
      return;
    }
    if (!catalogs.socios?.length) {
      setFeedback({ type: "warning", message: "No hay socios activos disponibles para liquidar una ayuda." });
      return;
    }
    setCreateOpen(true);
  };

  const openDetail = async (item) => {
    setOpeningDetail(true);
    try {
      const response = await getAyudaDetalle(item.id_ayuda);
      setDetail(response);
    } catch (error) {
      setFeedback({ type: "error", message: error?.message || "No se pudo abrir la ayuda." });
    } finally {
      setOpeningDetail(false);
    }
  };

  const afterLiquidated = async (response) => {
    setCreateOpen(false);
    setFeedback({
      type: "success",
      message: `Ayuda N° ${aidNumber(response?.numero_ayuda)} liquidada. El importe fue acreditado en la caja de ahorro común.`,
      duration: 6500,
    });
    await Promise.all([load({ query: search }), loadCatalogs()]);
    if (response?.detalle) setDetail(response.detalle);
  };

  const afterRenewed = async (response) => {
    setRenewalDetail(null);
    setDetail(null);
    setFeedback({
      type: "success",
      message: `Renovación registrada como ayuda N° ${aidNumber(response?.numero_ayuda)} sin duplicar el desembolso del capital.`,
      duration: 6500,
    });
    await Promise.all([load({ query: search }), loadCatalogs()]);
    if (response?.detalle) setDetail(response.detalle);
  };

  const afterAnnulled = async (response) => {
    setAnnulDetail(null);
    setDetail(null);
    const reversed = Boolean(response?.reverso_caja?.reversed);
    setFeedback({
      type: "success",
      message: reversed
        ? "La liquidación fue anulada y la acreditación fue revertida de la caja de ahorro común."
        : "La liquidación fue anulada conservando el historial. No existía un desembolso de capital para revertir.",
      duration: 6500,
    });
    await Promise.all([load({ query: search }), loadCatalogs()]);
  };

  const typeOptions = useMemo(
    () => (catalogs.productos || []).map((item) => ({ value: item.codigo, label: `${item.codigo} · ${item.nombre}` })),
    [catalogs.productos],
  );

  const filters = [
    {
      key: "status",
      label: "Estado",
      type: "tabs",
      ariaLabel: "Estado de las ayudas",
      value: status,
      onChange: setStatus,
      options: [
        { value: "VIGENTES", label: "Vigentes" },
        { value: "CERRADAS", label: "Cerradas" },
      ],
    },
    {
      key: "search",
      label: "Buscar por ayuda, socio, nombre o documento",
      type: "search",
      placeholder: " ",
      value: search,
      onChange: setSearch,
      className: "ayudas-search-filter",
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
  ];

  const secondaryActions = canManage
    ? [{
        key: "parameters",
        label: "Tasas y dólar",
        icon: "edit",
        onClick: () => setParametersOpen(true),
      }]
    : [];

  return (
    <>
      <ModulePage
        canCreate={canManage}
        description={moduleConfig.description}
        filters={filters}
        onPrimaryAction={openCreate}
        onRefresh={() => Promise.all([load({ notify: true, query: search }), loadCatalogs()])}
        primaryActionLabel="Nueva ayuda"
        refreshing={loading && records.length > 0}
        secondaryActions={secondaryActions}
        tabsInTitle
        title={moduleConfig.title || "Ayudas económicas"}
      >
        <ModuleFeedback
          duration={feedback?.duration}
          message={feedback?.message}
          onClose={() => setFeedback(null)}
          type={feedback?.type}
        />

        <div className="ayudas-module-note">
          <GlobalIcon name="info" size={18} />
          <span>
            Tipos implementados: A compra de cheques; B renovable a 30/60 días; E cuotas por sistema directo; I cuotas en dólares; J sistema francés mensual o semestral.
          </span>
        </div>

        <GlobalDivTable
          ariaLabel="Listado de ayudas económicas"
          bodyClassName="ayudas-global-table__body"
          className="ayudas-global-table"
          columns={["Ayuda", "Socio", "Tipo", "Capital", "Acreditado", "Vencimiento", "Saldo pendiente", "Estado", "Acciones"]}
          gridClassName="ayudas-global-grid"
        >
          {loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon className="is-spinning" name="loader" size={28} />
              <strong>Cargando ayudas...</strong>
              <span>Consultando liquidaciones, planes y vencimientos.</span>
            </div>
          ) : null}

          {!loading && !records.length ? (
            <div className="global-table-empty">
              <GlobalIcon name="inbox" size={30} />
              <strong>Sin ayudas para mostrar</strong>
              <span>Creá la primera liquidación o cambiá los filtros.</span>
            </div>
          ) : null}

          {records.map((item) => {
            const overdue = Boolean(Number(item.vencida || 0));
            const currency = item.moneda || "ARS";
            const renewable = canManage && item.tipo === "B" && item.estado === "VIGENTE" && item.fecha_vencimiento <= localDateValue();
            return (
              <div className="global-div-table__row ayudas-global-grid" key={item.id_ayuda} role="row">
                <div className="global-table-cell global-table-cell--main" role="cell">
                  <strong>N° {aidNumber(item.numero_ayuda)}</strong>
                  <small>Solicitud {aidNumber(item.numero_solicitud)}</small>
                </div>
                <div className="global-table-cell global-table-cell--main" role="cell">
                  <strong>{item.socio_nombre}</strong>
                  <small>Socio N° {item.numero_socio} · {item.documento || "S/D"}</small>
                </div>
                <div className="global-table-cell ayuda-type-cell" role="cell">
                  <span className="ayuda-type-badge">{item.tipo}</span>
                  <small>{item.producto_nombre}</small>
                </div>
                <div className="global-table-cell is-right is-strong" role="cell">
                  {formatMoney(item.capital_original, currency)}
                  {item.tipo === "I" ? <small>{formatMoney(item.capital_equivalente_ars, "ARS")}</small> : null}
                </div>
                <div className="global-table-cell is-right" role="cell">
                  {formatMoney(item.importe_acreditado_ars, "ARS")}
                </div>
                <div className="global-table-cell is-center" role="cell">
                  <strong>{formatDate(item.fecha_vencimiento)}</strong>
                  <small>{item.cuotas_pendientes} pendiente{Number(item.cuotas_pendientes) === 1 ? "" : "s"}</small>
                </div>
                <div className="global-table-cell is-right is-strong" role="cell">
                  {formatMoney(item.saldo_pendiente, currency)}
                  {item.proximo_vencimiento ? <small>Próx. {formatDate(item.proximo_vencimiento)}</small> : null}
                </div>
                <div className="global-table-cell is-center" role="cell">
                  <span className={`global-chip ${statusTone(item.estado, overdue)}`}>
                    {statusLabel(item.estado, overdue)}
                  </span>
                </div>
                <div className="global-table-cell global-table-cell--actions" role="cell">
                  <div className="global-table-actions">
                    <button aria-label={`Ver ayuda ${aidNumber(item.numero_ayuda)}`} className="global-icon-button" disabled={openingDetail} onClick={() => openDetail(item)} title="Ver información" type="button">
                      <GlobalIcon name="info" size={16} />
                    </button>
                    {renewable ? (
                      <button aria-label={`Renovar ayuda ${aidNumber(item.numero_ayuda)}`} className="global-icon-button" onClick={async () => {
                        try {
                          const response = await getAyudaDetalle(item.id_ayuda);
                          setRenewalDetail(response);
                        } catch (error) {
                          setFeedback({ type: "error", message: error?.message || "No se pudo preparar la renovación." });
                        }
                      }} title="Renovar ayuda B" type="button">
                        <GlobalIcon name="refresh" size={16} />
                      </button>
                    ) : null}
                  </div>
                </div>
              </div>
            );
          })}
        </GlobalDivTable>
      </ModulePage>

      <AyudaModal
        catalogs={catalogs}
        onClose={() => setCreateOpen(false)}
        onLiquidated={afterLiquidated}
        open={createOpen}
      />
      <AyudaDetalleModal
        canManage={canManage}
        detail={detail}
        onAnnul={(value) => { setDetail(null); setAnnulDetail(value); }}
        onClose={() => setDetail(null)}
        onRenew={(value) => { setDetail(null); setRenewalDetail(value); }}
        open={Boolean(detail)}
      />
      <AyudaRenovacionModal
        detail={renewalDetail}
        onClose={() => setRenewalDetail(null)}
        onRenewed={afterRenewed}
        open={Boolean(renewalDetail)}
      />
      <AyudaAnulacionModal
        detail={annulDetail}
        onAnnulled={afterAnnulled}
        onClose={() => setAnnulDetail(null)}
        open={Boolean(annulDetail)}
      />
      <AyudaParametrosModal
        onClose={() => setParametersOpen(false)}
        onSaved={loadCatalogs}
        open={parametersOpen}
      />
    </>
  );
}
