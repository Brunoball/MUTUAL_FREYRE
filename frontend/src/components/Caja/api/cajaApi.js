import { apiGet } from "../../Global/api/httpClient";

export const getCajaStructure = () => apiGet("caja");
