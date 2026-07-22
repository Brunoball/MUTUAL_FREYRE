import {
  faAddressCard,
  faBuildingColumns,
  faCashRegister,
  faChartColumn,
  faFileLines,
  faGear,
  faHandHoldingDollar,
  faHouse,
  faMoneyBillTransfer,
  faMoneyCheckDollar,
  faPiggyBank,
  faScaleBalanced,
  faShieldHalved,
  faVault,
} from "@fortawesome/free-solid-svg-icons";

export const CONFIGURATION_NAV_ITEM = {
  label: "Configuración",
  path: "/configuracion",
  icon: faGear,
  permission: "configuracion.view",
};

export const NAVIGATION_GROUPS = [
  {
    label: "Principal",
    items: [
      { label: "Inicio", path: "/panel", icon: faHouse, permission: "dashboard.view" },
    ],
  },
  {
    label: "Gestión mutual",
    items: [
      { label: "Personas y asociados", path: "/personas", icon: faAddressCard, permission: "personas.view" },
      { label: "Ayudas económicas", path: "/ayudas", icon: faHandHoldingDollar, permission: "ayudas.view" },
      { label: "Cobranzas y mora", path: "/cobranzas", icon: faMoneyBillTransfer, permission: "cobranzas.view" },
    ],
  },
  {
    label: "Ahorro y valores",
    items: [
      { label: "Cuentas de socios", path: "/cuentas", icon: faPiggyBank, permission: "cuentas.view" },
      { label: "Ahorros a término", path: "/ahorros", icon: faVault, permission: "ahorros.view" },
      { label: "Valores y cheques", path: "/valores", icon: faMoneyCheckDollar, permission: "valores.view" },
    ],
  },
  {
    label: "Tesorería y control",
    items: [
      { label: "Caja y tesorería", path: "/caja", icon: faCashRegister, permission: "caja.view" },
      { label: "Bancos", path: "/bancos", icon: faBuildingColumns, permission: "bancos.view" },
      { label: "Contabilidad", path: "/contabilidad", icon: faScaleBalanced, permission: "contabilidad.view" },
    ],
  },
  {
    label: "Información",
    items: [
      { label: "Documentos", path: "/documentos", icon: faFileLines, permission: "documentos.view" },
      { label: "Reportes", path: "/reportes", icon: faChartColumn, permission: "reportes.view" },
      { label: "Auditoría", path: "/auditoria", icon: faShieldHalved, permission: "auditoria.view" },
    ],
  },
];
