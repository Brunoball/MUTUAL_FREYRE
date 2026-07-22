import { apiGet } from "../../shared/httpClient";

export const getAyudas = (params) => apiGet("ayudas", params);

// Se conserva el nombre original para no romper importaciones existentes.
export const getAyudasStructure = getAyudas;
