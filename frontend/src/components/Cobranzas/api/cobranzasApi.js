import { apiGet } from "../../_shared/httpClient";

export const getCobranzasStructure = () => apiGet("cobranzas");
