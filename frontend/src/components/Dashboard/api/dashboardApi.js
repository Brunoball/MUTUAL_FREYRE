import { apiGet } from "../../Global/api/httpClient";

export const getDashboard = () => apiGet("dashboard");
