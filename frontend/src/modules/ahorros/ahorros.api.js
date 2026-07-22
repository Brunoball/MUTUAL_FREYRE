import { apiGet } from "../../shared/httpClient";

export const getAhorrosTerminoStructure = () => apiGet("ahorros");
