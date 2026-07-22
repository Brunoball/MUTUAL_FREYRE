import { apiGet } from "../../shared/httpClient";

export const getCajaStructure = () => apiGet("caja");
