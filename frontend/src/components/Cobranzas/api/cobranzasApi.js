import { apiGet } from "../../Global/api/httpClient";

export const getCobranzasStructure = () => apiGet("cobranzas");
