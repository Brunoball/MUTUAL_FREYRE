import React, { useCallback, useEffect, useMemo, useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faAddressCard,
  faArrowRight,
  faCalendarAlt,
  faCheckCircle,
  faClock,
  faDollarSign,
  faExclamationTriangle,
  faLink,
  faUsers,
  faWallet,
} from "@fortawesome/free-solid-svg-icons";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../../app/AuthProvider";
import { getDashboard } from "./dashboard.api";
import "./Dashboard.css";

const EMPTY_DATA = {
  resumen: {},
  cartera_por_producto: [],
};

const numberFormatter = new Intl.NumberFormat("es-AR");
const moneyFormatter = new Intl.NumberFormat("es-AR", {
  style: "currency",
  currency: "ARS",
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});
const usdFormatter = new Intl.NumberFormat("es-AR", {
  style: "currency",
  currency: "USD",
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

const formatNumber = (value) => numberFormatter.format(Number(value || 0));
const formatMoney = (value, currency = "ARS") =>
  (currency === "USD" ? usdFormatter : moneyFormatter).format(Number(value || 0));

function KpiCard({ icon, label, value, detail, tone, onClick }) {
  const interactive = typeof onClick === "function";
  const Component = interactive ? "button" : "article";

  return (
    <Component
      className={`dashboard-kpi dashboard-kpi--${tone || "blue"} ${interactive ? "is-clickable" : ""}`}
      {...(interactive ? { type: "button", onClick } : {})}
    >
      <span className="dashboard-kpi__icon"><FontAwesomeIcon icon={icon} /></span>
      <div>
        <span className="dashboard-kpi__label">{label}</span>
        <strong>{value}</strong>
        <small>{detail}</small>
      </div>
      {interactive && <FontAwesomeIcon className="dashboard-kpi__arrow" icon={faArrowRight} />}
    </Component>
  );
}

export default function DashboardPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [data, setData] = useState(EMPTY_DATA);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await getDashboard();
      setData({ ...EMPTY_DATA, ...(response || {}) });
    } catch (loadError) {
      setError(loadError?.message || "No se pudo cargar el resumen del sistema.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const summary = data.resumen || {};
  const maxPortfolio = useMemo(
    () => Math.max(1, ...(data.cartera_por_producto || []).map((item) => Number(item.saldo_pendiente || 0))),
    [data.cartera_por_producto],
  );
  const hasAlerts = Number(summary.cuotas_vencidas || 0) > 0;

  const goTo = (permission, path) => (can(permission) ? () => navigate(path) : undefined);

  return (
    <section className="dashboard-page">
      <header className="dashboard-header">
        <div>
          <p>Resumen operativo</p>
          <h1>Panel general</h1>
          <span>Vista rápida de asociados, personas, ayudas económicas y saldos.</span>
        </div>
      </header>

      {error && (
        <div className="dashboard-error">
          <FontAwesomeIcon icon={faExclamationTriangle} />
          <span>{error}</span>
          <button type="button" onClick={load}>Reintentar</button>
        </div>
      )}

      <div className={`dashboard-kpi-grid ${loading ? "is-loading" : ""}`}>
        <KpiCard
          icon={faUsers}
          label="Socios activos"
          value={formatNumber(summary.asociados_activos)}
          detail={`${formatNumber(summary.asociados_totales)} socios registrados`}
          tone="green"
          onClick={goTo("personas.view", "/personas")}
        />
        <KpiCard
          icon={faAddressCard}
          label="Personas activas"
          value={formatNumber(summary.personas_activas)}
          detail={`${formatNumber(summary.personas_fisicas)} físicas · ${formatNumber(summary.personas_juridicas)} jurídicas`}
          tone="blue"
          onClick={goTo("personas.view", "/personas")}
        />
        <KpiCard
          icon={faDollarSign}
          label="Ayudas vigentes"
          value={formatNumber(summary.ayudas_vigentes)}
          detail={`${formatMoney(summary.capital_vigente_ars)} de capital vigente`}
          tone="gold"
          onClick={goTo("ayudas.view", "/ayudas")}
        />
        <KpiCard
          icon={faWallet}
          label="Saldos en cuentas"
          value={formatMoney(summary.saldo_cuentas_ars)}
          detail={`${formatNumber(summary.cuentas_ahorro_activas)} cuentas activas${Number(summary.saldo_cuentas_usd || 0) ? ` · ${formatMoney(summary.saldo_cuentas_usd, "USD")}` : ""}`}
          tone="teal"
          onClick={goTo("cuentas.view", "/cuentas")}
        />
      </div>

      <div className="dashboard-main-grid">
        <article className="dashboard-panel dashboard-panel--portfolio">
          <div className="dashboard-panel__heading">
            <div>
              <p>Composición de la cartera</p>
              <h2>Ayudas por producto</h2>
              <span>Saldo pendiente y cantidad de operaciones vigentes.</span>
            </div>
            <div className="dashboard-panel__total">
              <small>Cartera pendiente</small>
              <strong>{formatMoney(summary.cartera_pendiente_ars)}</strong>
            </div>
          </div>

          <div className="portfolio-list">
            {(data.cartera_por_producto || []).map((item) => {
              const width = Math.max(0, (Number(item.saldo_pendiente || 0) / maxPortfolio) * 100);
              return (
                <div className="portfolio-row" key={item.codigo}>
                  <span className={`portfolio-code portfolio-code--${String(item.codigo).toLowerCase()}`}>{item.codigo}</span>
                  <div className="portfolio-row__content">
                    <div className="portfolio-row__top">
                      <div>
                        <strong>{item.nombre}</strong>
                        <small>{formatNumber(item.cantidad_ayudas)} ayudas vigentes</small>
                      </div>
                      <b>{formatMoney(item.saldo_pendiente, item.moneda)}</b>
                    </div>
                    <div className="portfolio-track"><span style={{ width: `${width}%` }} /></div>
                  </div>
                </div>
              );
            })}
            {!loading && !(data.cartera_por_producto || []).length && (
              <div className="dashboard-empty">No hay productos activos para mostrar.</div>
            )}
          </div>
        </article>

        <aside className="dashboard-panel dashboard-status-panel">
          <div className="dashboard-status-panel__heading">
            <div>
              <p>Estado operativo</p>
              <h2>Indicadores principales</h2>
            </div>
            <span className={`dashboard-health ${hasAlerts ? "has-alerts" : "is-ok"}`}>
              <FontAwesomeIcon icon={hasAlerts ? faExclamationTriangle : faCheckCircle} />
              {hasAlerts ? "Requiere atención" : "Sin vencidos"}
            </span>
          </div>

          <div className={`dashboard-alert-card ${hasAlerts ? "is-danger" : "is-clear"}`}>
            <span><FontAwesomeIcon icon={hasAlerts ? faExclamationTriangle : faCheckCircle} /></span>
            <div>
              <small>Cuotas vencidas</small>
              <strong>{formatNumber(summary.cuotas_vencidas)}</strong>
              <b>{formatMoney(summary.importe_vencido_ars)}</b>
            </div>
          </div>

          <div className="dashboard-indicator-grid">
            <div className="dashboard-indicator">
              <span><FontAwesomeIcon icon={faClock} /></span>
              <small>Próximos 7 días</small>
              <strong>{formatNumber(summary.cuotas_proximas_7)} cuotas</strong>
              <b>{formatMoney(summary.importe_proximo_7_ars)}</b>
            </div>
            <div className="dashboard-indicator">
              <span><FontAwesomeIcon icon={faCalendarAlt} /></span>
              <small>Próximos 30 días</small>
              <strong>{formatNumber(summary.cuotas_proximas_30)} cuotas</strong>
              <b>{formatMoney(summary.importe_proximo_30_ars)}</b>
            </div>
            <div className="dashboard-indicator">
              <span><FontAwesomeIcon icon={faLink} /></span>
              <small>Vínculos activos</small>
              <strong>{formatNumber(summary.vinculos_activos)}</strong>
              <b>Relaciones vigentes</b>
            </div>
            <div className="dashboard-indicator">
              <span><FontAwesomeIcon icon={faWallet} /></span>
              <small>Capital vigente</small>
              <strong>{formatMoney(summary.capital_vigente_ars)}</strong>
              <b>{formatNumber(summary.ayudas_vigentes)} operaciones</b>
            </div>
          </div>
        </aside>
      </div>

      <footer className="dashboard-footer">Desarrollado por <strong>3devs.solutions</strong></footer>
    </section>
  );
}
