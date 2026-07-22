import { apiGet } from "../../shared/httpClient";

export const getPersonas = (params) => apiGet("personas", params);

// Se conserva el nombre original para no romper importaciones existentes.
export const getPersonasStructure = getPersonas;
