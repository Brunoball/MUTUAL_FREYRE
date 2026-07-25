import { apiGet } from "../../Global/api/httpClient";

export const getAhorrosTerminoStructure = () => apiGet("ahorros");
