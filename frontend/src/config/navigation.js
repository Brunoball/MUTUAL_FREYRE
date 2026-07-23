import {
  faAddressCard,
  faBuildingColumns,
  faCashRegister,
  faGear,
  faHandHoldingDollar,
  faHouse,
  faMoneyBillTransfer,
  faMoneyCheckDollar,
  faPiggyBank,
  faScaleBalanced,
  faVault,
} from "@fortawesome/free-solid-svg-icons";

export const CONFIGURATION_NAV_ITEM = {
  label: "Configuración",
  path: "/configuracion",
  icon: faGear,
  permission: "configuracion.view",
  enabled: true,
};

export const NAVIGATION_GROUPS = [
  {
    label: "Principal",
    items: [
      { label: "Inicio", path: "/panel", icon: faHouse, permission: "dashboard.view", enabled: true },
    ],
  },
  {
    label: "Gestión mutual",
    items: [
      { label: "Personas y asociados", path: "/personas", icon: faAddressCard, permission: "personas.view", enabled: true },
      { label: "Ayudas económicas", path: "/ayudas", icon: faHandHoldingDollar, permission: "ayudas.view", enabled: true },
      { label: "Cobranzas y mora", path: "/cobranzas", icon: faMoneyBillTransfer, permission: "cobranzas.view", enabled: false },
    ],
  },
  {
    label: "Ahorro y valores",
    items: [
      { label: "Cuentas de socios", path: "/cuentas", icon: faPiggyBank, permission: "cuentas.view", enabled: false },
      { label: "Ahorros a término", path: "/ahorros", icon: faVault, permission: "ahorros.view", enabled: false },
      { label: "Valores y cheques", path: "/valores", icon: faMoneyCheckDollar, permission: "valores.view", enabled: false },
    ],
  },
  {
    label: "Tesorería y control",
    items: [
      { label: "Caja y tesorería", path: "/caja", icon: faCashRegister, permission: "caja.view", enabled: false },
      { label: "Bancos", path: "/bancos", icon: faBuildingColumns, permission: "bancos.view", enabled: false },
      { label: "Contabilidad", path: "/contabilidad", icon: faScaleBalanced, permission: "contabilidad.view", enabled: false },
    ],
  },
];
