const trimTrailingSlash = (value) => String(value || "").trim().replace(/\/+$/, "");

export const APP_NAME = process.env.REACT_APP_NAME || "Sistema Integral Mutual";
export const INSTITUTION_NAME = process.env.REACT_APP_INSTITUTION_NAME || "Mutual 9 de Julio de Freyre";
export const API_BASE_URL = trimTrailingSlash(
  process.env.REACT_APP_API_URL || "http://localhost:3001/api/backoffice/v1"
);
