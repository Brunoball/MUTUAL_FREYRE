import { toUpperText } from "./globalOptions";

const CATALOG_KEYS = Object.freeze([
  "paises",
  "provincias",
  "localidades",
  "zonas_geograficas",
  "condiciones_iva",
  "relaciones_laborales",
  "tipos_societarios",
  "categorias_asociados",
  "sucursales",
  "personas_vinculables",
]);

export const EMPTY_PERSONAS_CATALOGS = Object.freeze(
  Object.fromEntries(CATALOG_KEYS.map((key) => [key, Object.freeze([])]))
);

const normalizeItem = (item) => Object.fromEntries(
  Object.entries(item || {}).map(([key, value]) => [
    key,
    typeof value === "string" ? toUpperText(value) : value,
  ])
);

export const normalizePersonasCatalogs = (payload = {}) => Object.fromEntries(
  CATALOG_KEYS.map((key) => [
    key,
    Array.isArray(payload?.[key]) ? payload[key].map(normalizeItem) : [],
  ])
);
