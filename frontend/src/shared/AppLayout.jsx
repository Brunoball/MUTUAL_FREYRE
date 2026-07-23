import React, { useMemo, useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faBars, faRightFromBracket, faUserCircle, faXmark } from "@fortawesome/free-solid-svg-icons";
import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import { APP_NAME, INSTITUTION_NAME } from "../config/env";
import { CONFIGURATION_NAV_ITEM, NAVIGATION_GROUPS } from "../config/navigation";
import { useAuth } from "../app/AuthProvider";
import mutualLogo from "../assets/images/logo_perfil_sf.png";
import "./AppLayout.css";

export default function AppLayout() {
  const [open, setOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const auth = useAuth();

  const visibleGroups = useMemo(() => NAVIGATION_GROUPS
    .map((group) => ({ ...group, items: group.items.filter((item) => auth.can(item.permission)) }))
    .filter((group) => group.items.length > 0), [auth]);

  const activeLabel = useMemo(() => {
    const navigationItems = [
      ...NAVIGATION_GROUPS.flatMap((group) => group.items),
      CONFIGURATION_NAV_ITEM,
    ];

    const item = navigationItems.find((entry) => (
      location.pathname === entry.path || location.pathname.startsWith(`${entry.path}/`)
    ));

    return item?.label || "Inicio";
  }, [location.pathname]);

  const logout = async () => {
    setLoggingOut(true);
    await auth.logout();
    navigate("/", { replace: true });
  };

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="topbar__left">
          <button className="icon-button topbar__menu" type="button" onClick={() => setOpen(true)} aria-label="Abrir menú">
            <FontAwesomeIcon icon={faBars} />
          </button>
          <button className="topbar__brand" type="button" onClick={() => navigate("/panel")}>
            <span className="brand-logo brand-logo--topbar"><img src={mutualLogo} alt="" /></span>
            <div><strong>{APP_NAME}</strong><small>{INSTITUTION_NAME}</small></div>
          </button>
        </div>

        <div className="topbar__right">
          <span className="topbar__section">{activeLabel}</span>
          <div className="topbar__user">
            <FontAwesomeIcon icon={faUserCircle} />
            <div>
              <strong>{auth.usuario?.nombre || auth.usuario?.username || "Usuario"}</strong>
              <span>{auth.usuario?.rol || ""}</span>
            </div>
          </div>

          {auth.can(CONFIGURATION_NAV_ITEM.permission) && (
            <NavLink
              className={({ isActive }) => `icon-button topbar__config ${isActive ? "is-active" : ""}`}
              to={CONFIGURATION_NAV_ITEM.path}
              title={CONFIGURATION_NAV_ITEM.label}
              aria-label={CONFIGURATION_NAV_ITEM.label}
            >
              <FontAwesomeIcon icon={CONFIGURATION_NAV_ITEM.icon} />
            </NavLink>
          )}

          <button
            className="icon-button"
            type="button"
            onClick={logout}
            disabled={loggingOut}
            title="Cerrar sesión"
            aria-label="Cerrar sesión"
          >
            <FontAwesomeIcon icon={faRightFromBracket} />
          </button>
        </div>
      </header>

      <div className={`sidebar-overlay ${open ? "is-open" : ""}`} onClick={() => setOpen(false)} />
      <aside
        className={`sidebar ${open ? "is-open" : ""}`}
        onMouseLeave={() => setOpen(false)}
      >
        <div className="sidebar__header">
          <button
            className="sidebar__brand"
            type="button"
            onClick={() => navigate("/panel")}
            title={APP_NAME}
          >
            <span className="brand-logo brand-logo--sidebar"><img src={mutualLogo} alt="" /></span>
            <div><strong>{APP_NAME}</strong><small>Backoffice interno</small></div>
          </button>
          <button className="icon-button sidebar__close" type="button" onClick={() => setOpen(false)} aria-label="Cerrar menú">
            <FontAwesomeIcon icon={faXmark} />
          </button>
        </div>

        <nav className="sidebar__nav" aria-label="Navegación principal">
          {visibleGroups.map((group) => (
            <div className="nav-group" key={group.label}>
              <p>{group.label}</p>
              {group.items.map((item) => (
                <NavLink
                  className={({ isActive }) => `nav-item ${isActive ? "is-active" : ""}`}
                  to={item.path}
                  key={item.path}
                  title={item.label}
                >
                  <span><FontAwesomeIcon icon={item.icon} /></span>
                  <strong>{item.label}</strong>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>
      </aside>

      <main className="app-content">
        <div className="app-content__inner"><Outlet /></div>
      </main>
    </div>
  );
}
