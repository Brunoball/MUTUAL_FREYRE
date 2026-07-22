import { apiGet } from "../../shared/httpClient";

export const getDashboard = () => apiGet("dashboard");
