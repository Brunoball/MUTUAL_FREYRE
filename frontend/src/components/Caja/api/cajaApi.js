import { apiGet } from "../../_shared/httpClient";

export const getCajaStructure = () => apiGet("caja");
