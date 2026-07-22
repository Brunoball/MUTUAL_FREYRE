import { apiGet } from "../../shared/httpClient";

export const getCobranzasStructure = () => apiGet("cobranzas");
